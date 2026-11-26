<?php
/* Copyright (C) 2026 Blokbay <mail@blokbay.com>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    lib/blokbay.lib.php
 * \ingroup blokbay
 * \brief   Blokbay API client and the logic shared by the invoice card, the webhook and the triggers.
 *
 * "Pay with Blokbay" creates a Blokbay invoice (POST /api/v1/invoices, send: false) with the customer, lines and taxes of the
 * Dolibarr invoice, keeps the payment link (invoice card, PDF, invoice email) and, when the signed webhook invoice.paid arrives,
 * reads the invoice again from the API and registers the payment once on the bank account chosen in the settings.
 */

/** Currencies accepted by Blokbay invoices, with their decimals. */
function blokbay_currencies()
{
	return array('USD' => 2, 'EUR' => 2, 'GBP' => 2, 'CHF' => 2, 'JPY' => 0, 'CAD' => 2, 'AUD' => 2, 'SGD' => 2, 'HKD' => 2, 'AED' => 2);
}

/** Languages of the Blokbay payment page. */
function blokbay_langs()
{
	return array('en', 'it', 'fr', 'de', 'es', 'pt', 'ru', 'zh', 'ja', 'hi', 'ko', 'tr', 'id', 'ar', 'vi', 'pl');
}

/** Flag set while the webhook registers a payment, so the triggers do not cancel the link that was just paid. */
$GLOBALS['blokbay_processing'] = 0;

/**
 * Base URL of the API, without trailing slash.
 *
 * @return string
 */
function blokbay_api_base()
{
	$url = trim(getDolGlobalString('BLOKBAY_API_URL', 'https://blokbay.com'));
	if (!preg_match('~^https?://[^\s/?#]+(:\d+)?(/[^\s?#]*)?$~i', $url)) {
		$url = 'https://blokbay.com';
	}
	return rtrim($url, '/');
}

/**
 * Secret key sk_live_ saved in the settings (decrypted by Dolibarr when the constants are loaded).
 *
 * @return string
 */
function blokbay_secret_key()
{
	return trim(getDolGlobalString('BLOKBAY_SECRET_KEY'));
}

/**
 * True when the module has an API key.
 *
 * @return bool
 */
function blokbay_is_configured()
{
	return (bool) preg_match('/^sk_live_[A-Za-z0-9]{24,64}$/', blokbay_secret_key());
}

/**
 * Public URL of the webhook, to copy in the Blokbay panel.
 *
 * @return string
 */
function blokbay_webhook_url()
{
	global $conf;
	$url = dol_buildpath('/blokbay/public/webhook.php', 3);
	if (!empty($conf->entity) && (int) $conf->entity > 1) {
		$url .= '?entity='.((int) $conf->entity);
	}
	return $url;
}

/**
 * Call the Blokbay API from the server (never from the browser: the secret key stays private).
 *
 * @param string     $method          GET or POST
 * @param string     $path            Path starting with /api/v1/
 * @param array|null $payload         JSON body
 * @param string     $idempotencyKey  Idempotency-Key header (POST)
 * @return array{status:int,data:array,error:string,message:string}
 */
function blokbay_request($method, $path, $payload = null, $idempotencyKey = '')
{
	$key = blokbay_secret_key();
	if ($key === '') {
		return array('status' => 0, 'data' => array(), 'error' => 'no_key', 'message' => 'Missing API key');
	}
	$headers = array(
		'Authorization: Bearer '.$key,
		'Accept: application/json',
		'User-Agent: Blokbay-Dolibarr/1.0 (Dolibarr '.(defined('DOL_VERSION') ? DOL_VERSION : '').')',
	);
	if ($idempotencyKey !== '') {
		$headers[] = 'Idempotency-Key: '.substr($idempotencyKey, 0, 100);
	}
	$ch = curl_init(blokbay_api_base().$path);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
	curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
	curl_setopt($ch, CURLOPT_TIMEOUT, 30);
	curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS | CURLPROTO_HTTP);
	if ($method === 'POST') {
		curl_setopt($ch, CURLOPT_POST, true);
		$headers[] = 'Content-Type: application/json';
		curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload === null ? new stdClass() : $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
	}
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	$body = curl_exec($ch);
	$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
	$curlerr = curl_error($ch);
	curl_close($ch);
	if ($body === false) {
		dol_syslog('Blokbay: API unreachable: '.$curlerr, LOG_WARNING);
		return array('status' => 0, 'data' => array(), 'error' => 'network', 'message' => $curlerr);
	}
	$data = json_decode((string) $body, true);
	if (!is_array($data)) {
		$data = array();
	}
	$error = '';
	$message = '';
	if ($status < 200 || $status >= 300) {
		$error = is_string($data['error'] ?? null) ? $data['error'] : 'http_'.$status;
		$message = is_string($data['message'] ?? null) ? $data['message'] : '';
		dol_syslog('Blokbay: API '.$method.' '.$path.' answered '.$status.' '.$error, LOG_WARNING);
	}
	return array('status' => $status, 'data' => $data, 'error' => $error, 'message' => $message);
}

/**
 * Check the Blokbay invoice returned by the API: id and payment URL on the configured API host.
 *
 * @param array $inv Invoice object
 * @return bool
 */
function blokbay_valid_invoice($inv)
{
	if (!is_array($inv) || !preg_match('/^inv_[a-z0-9]{6,40}$/', (string) ($inv['id'] ?? ''))) {
		return false;
	}
	$url = (string) ($inv['url'] ?? '');
	return (bool) preg_match('#^'.preg_quote(blokbay_api_base(), '#').'/i/[0-9a-f]{64}$#', $url);
}

/**
 * Verify the Blokbay-Signature header: t=<unix seconds>,v1=<hex HMAC-SHA256 of "t.body">, 300 seconds of tolerance.
 *
 * @param string $raw     Raw body
 * @param string $header  Header value
 * @param string $secret  whsec_ secret
 * @param int    $now     Current time
 * @return bool
 */
function blokbay_verify_signature($raw, $header, $secret, $now = 0)
{
	if ($secret === '' || $header === '') {
		return false;
	}
	$t = 0;
	$sigs = array();
	foreach (explode(',', $header) as $part) {
		$kv = explode('=', trim($part), 2);
		if (count($kv) !== 2) {
			continue;
		}
		if ($kv[0] === 't' && ctype_digit($kv[1])) {
			$t = (int) $kv[1];
		} elseif ($kv[0] === 'v1' && preg_match('/^[0-9a-f]{64}$/', $kv[1])) {
			$sigs[] = $kv[1];
		}
	}
	$now = $now ?: time();
	if (!$t || abs($now - $t) > 300 || !$sigs) {
		return false;
	}
	$expected = hash_hmac('sha256', $t.'.'.$raw, $secret);
	foreach ($sigs as $sig) {
		if (hash_equals($expected, $sig)) {
			return true;
		}
	}
	return false;
}

/**
 * Remove what Blokbay refuses in texts (web addresses, "www.", HTML tags) and control characters.
 *
 * @param string $s      Text
 * @param int    $max    Maximum length
 * @param bool   $domains Also remove bare domain names (number, issuer name, tax id)
 * @return string
 */
function blokbay_clean_text($s, $max, $domains = false)
{
	$s = html_entity_decode(strip_tags(str_replace(array('<br>', '<br/>', '<br />'), ' ', (string) $s)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$s = preg_replace('#https?://[^\s)\]>]*#iu', ' ', $s);
	$s = preg_replace('#\bwww\.[^\s)\]>]*#iu', ' ', $s);
	$s = preg_replace('#\(\s*(see|cf\.?|on)?\s*\)#iu', '', $s);
	$s = str_replace('<', ' ', $s);
	if ($domains) {
		$s = preg_replace('/(^|[^\p{L}\p{N}@-])[\p{L}\p{N}-]{1,63}\.(com|net|org|io|co|info|biz|xyz|app|dev|me|ru|cn|top|site|online|shop|store|link|click|live|pro|cc|ly|to|gg|it|de|fr|uk|es|eu|us|ch|nl|be|at|pl|tr|br|in|jp|kr|vn|id|ae|sa|ws|su)(?![\p{L}\p{N}])/iu', '$1', $s);
	}
	$s = trim(preg_replace('/[\p{Cc}\s]+/u', ' ', (string) $s));
	return dol_trunc($s, $max, 'right', 'UTF-8', 1);
}

/**
 * Amount in minor units (integer) from a Dolibarr amount.
 *
 * @param float|string $v   Amount
 * @param int          $dec Decimals of the currency
 * @return int
 */
function blokbay_minor($v, $dec)
{
	return (int) round(((float) $v) * pow(10, $dec));
}

/**
 * Format minor units as the string expected by the API ("12.50", JPY "1250").
 *
 * @param int $minor Minor units
 * @param int $dec   Decimals
 * @return string
 */
function blokbay_fmt($minor, $dec)
{
	if ($dec === 0) {
		return (string) $minor;
	}
	$neg = $minor < 0 ? '-' : '';
	$minor = abs($minor);
	$p = pow(10, $dec);
	return $neg.intdiv($minor, $p).'.'.str_pad((string) ($minor % $p), $dec, '0', STR_PAD_LEFT);
}

/**
 * Currency of the invoice for Blokbay: the invoice currency when multicurrency is used, otherwise the company currency.
 *
 * @param Facture $invoice Invoice
 * @return array{0:string,1:bool} Currency code and true when the multicurrency amounts are used
 */
function blokbay_invoice_currency($invoice)
{
	global $conf;
	if (isModEnabled('multicurrency') && !empty($invoice->multicurrency_code) && $invoice->multicurrency_code !== $conf->currency) {
		return array((string) $invoice->multicurrency_code, true);
	}
	return array((string) $conf->currency, false);
}

/**
 * Body of POST /api/v1/invoices built from the Dolibarr invoice. Each line goes with its net amount (discounts included) and the
 * taxes as one line, so the Blokbay total is exactly the Dolibarr total. A partly paid invoice (or one with negative lines, e.g.
 * deposits deducted) goes as one line with the amount still to pay.
 *
 * @param Facture   $invoice Invoice (fetched, with lines)
 * @param Translate $langs   Translations
 * @return array{payload:array,error:string}
 */
function blokbay_build_payload($invoice, $langs)
{
	global $conf, $mysoc, $db;

	list($currency, $mc) = blokbay_invoice_currency($invoice);
	$cur = blokbay_currencies();
	if (!isset($cur[$currency])) {
		return array('payload' => array(), 'error' => $langs->trans('BlokbayErrorCurrency', $currency, implode(', ', array_keys($cur))));
	}
	$dec = $cur[$currency];

	if (empty($invoice->thirdparty) || !is_object($invoice->thirdparty)) {
		$invoice->fetch_thirdparty();
	}
	$soc = $invoice->thirdparty;

	// Email: billing contact first, then the third party.
	$email = '';
	$contacts = $invoice->getIdContact('external', 'BILLING');
	if (!empty($contacts)) {
		require_once DOL_DOCUMENT_ROOT.'/contact/class/contact.class.php';
		$contact = new Contact($db);
		if ($contact->fetch((int) $contacts[0]) > 0 && isValidEmail($contact->email)) {
			$email = $contact->email;
		}
	}
	if ($email === '' && is_object($soc) && isValidEmail((string) $soc->email)) {
		$email = $soc->email;
	}
	if ($email === '') {
		return array('payload' => array(), 'error' => $langs->trans('BlokbayErrorEmail'));
	}

	$totalTtc = blokbay_minor($mc ? $invoice->multicurrency_total_ttc : $invoice->total_ttc, $dec);
	$remain = blokbay_minor($invoice->getRemainToPay($mc ? 1 : 0), $dec);
	if ($remain <= 0) {
		return array('payload' => array(), 'error' => $langs->trans('BlokbayErrorNothingToPay'));
	}

	$lines = array();
	$single = ($remain !== $totalTtc);
	$sumNet = 0;
	foreach ((array) $invoice->lines as $line) {
		if ((int) $line->product_type === 9 || (int) $line->special_code === 104777) {
			continue; // titles and subtotals
		}
		$net = blokbay_minor($mc ? $line->multicurrency_total_ht : $line->total_ht, $dec);
		if ($net === 0) {
			continue;
		}
		if ($net < 0) {
			$single = true;
			break;
		}
		$sumNet += $net;
		$name = (string) ($line->product_label ?? '');
		$desc = (string) ($line->desc ?? $line->description ?? '');
		if ($name === '' || ($desc !== '' && dol_strlen($desc) < 120 && $desc !== $name)) {
			$name = ($name !== '' ? $name.' - ' : '').$desc;
		}
		if (!empty($line->product_ref) && strpos($name, (string) $line->product_ref) === false) {
			$name = $line->product_ref.' '.$name;
		}
		$name = blokbay_clean_text($name, 250);
		if ($name === '') {
			$name = $langs->transnoentitiesnoconv('BlokbayItem');
		}
		$qty = (float) $line->qty;
		$unit = (float) ($mc ? $line->multicurrency_subprice : $line->subprice);
		$unitMinor = blokbay_minor($unit, $dec);
		$qtyStr = rtrim(rtrim(number_format($qty, 3, '.', ''), '0'), '.');
		$exact = $qty > 0 && empty((float) $line->remise_percent) && abs($qty - (float) $qtyStr) < 1e-9
			&& abs($unit * pow(10, $dec) - $unitMinor) < 1e-6 && (int) round($qtyStr * $unitMinor) === $net;
		if ($exact) {
			$lines[] = array('description' => $name, 'quantity' => $qtyStr, 'unit_price' => blokbay_fmt($unitMinor, $dec), 'tax_rate' => 0);
		} else {
			$lines[] = array('description' => dol_trunc($name.' ('.$qtyStr.')', 300, 'right', 'UTF-8', 1), 'quantity' => 1, 'unit_price' => blokbay_fmt($net, $dec), 'tax_rate' => 0);
		}
	}
	$tax = $totalTtc - $sumNet;
	if ($tax < 0 || count($lines) > 49) {
		$single = true;
	}
	if (!$single && $tax > 0) {
		$lines[] = array('description' => $langs->transnoentitiesnoconv('BlokbayTaxes'), 'quantity' => 1, 'unit_price' => blokbay_fmt($tax, $dec), 'tax_rate' => 0);
	}
	if ($single || !$lines) {
		$label = $remain === $totalTtc ? $langs->transnoentitiesnoconv('BlokbayInvoiceLine', $invoice->ref) : $langs->transnoentitiesnoconv('BlokbayBalanceLine', $invoice->ref);
		$lines = array(array('description' => blokbay_clean_text($label, 300), 'quantity' => 1, 'unit_price' => blokbay_fmt($remain, $dec), 'tax_rate' => 0));
	}

	$addr = function ($o) {
		$country = '';
		if (!empty($o->country_code)) {
			$country = getCountry($o->country_code, 0, null, null, 0);
		}
		$parts = array_filter(array(trim((string) $o->address), trim((string) $o->zip.' '.(string) $o->town), $country), 'strlen');
		return blokbay_clean_text(implode(', ', $parts), 500);
	};
	$customerName = blokbay_clean_text(is_object($soc) ? $soc->name : '', 200);
	$payload = array(
		'customer' => array(
			'name' => $customerName !== '' ? $customerName : $email,
			'email' => dol_trunc($email, 200, 'right', 'UTF-8', 1),
			'address' => is_object($soc) ? $addr($soc) : '',
			'tax_id' => blokbay_clean_text(is_object($soc) ? $soc->tva_intra : '', 60, true),
		),
		'issuer' => array(
			'name' => blokbay_clean_text($mysoc->name, 200, true),
			'email' => isValidEmail((string) $mysoc->email) ? dol_trunc($mysoc->email, 200, 'right', 'UTF-8', 1) : '',
			'address' => $addr($mysoc),
			'tax_id' => blokbay_clean_text($mysoc->tva_intra, 60, true),
		),
		'currency' => $currency,
		'lines' => $lines,
		'external_ref' => 'dolibarr:'.((int) $invoice->id),
		'number' => substr(blokbay_clean_text(preg_replace('#[^A-Za-z0-9/._-]#', '', (string) $invoice->ref), 40, true), 0, 40),
		'send' => false,
	);
	if ($payload['number'] === '') {
		unset($payload['number']);
	}
	if ($payload['issuer']['name'] === '') {
		unset($payload['issuer']);
	}
	$lang = '';
	if (is_object($soc) && !empty($soc->default_lang)) {
		$lang = substr((string) $soc->default_lang, 0, 2);
	}
	if (!in_array($lang, blokbay_langs(), true)) {
		$lang = substr((string) $langs->defaultlang, 0, 2);
	}
	if (in_array($lang, blokbay_langs(), true)) {
		$payload['lang'] = $lang;
	}
	if (!empty($invoice->date)) {
		$payload['issue_date'] = dol_print_date($invoice->date, '%Y-%m-%d');
	}
	if (!empty($invoice->date_lim_reglement) && (empty($invoice->date) || $invoice->date_lim_reglement >= $invoice->date)) {
		$payload['due_date'] = dol_print_date($invoice->date_lim_reglement, '%Y-%m-%d');
	}
	return array('payload' => $payload, 'error' => '');
}

/**
 * Row of llx_blokbay_invoice for an invoice.
 *
 * @param DoliDB $db       Database
 * @param int    $fkFacture Invoice id
 * @param bool   $forUpdate Lock the row (inside a transaction)
 * @return object|null
 */
function blokbay_get_row($db, $fkFacture, $forUpdate = false)
{
	$sql = 'SELECT rowid, entity, fk_facture, inv_id, pay_url, number, currency, total, status, attempt, fk_paiement, tx FROM '.$db->prefix().'blokbay_invoice';
	$sql .= ' WHERE fk_facture = '.((int) $fkFacture).($forUpdate ? ' FOR UPDATE' : '');
	$res = $db->query($sql);
	if ($res && ($obj = $db->fetch_object($res))) {
		return $obj;
	}
	return null;
}

/**
 * Row of llx_blokbay_invoice for a Blokbay invoice id.
 *
 * @param DoliDB $db    Database
 * @param string $invId inv_ id
 * @return object|null
 */
function blokbay_get_row_by_inv($db, $invId)
{
	global $conf;
	$sql = 'SELECT rowid, fk_facture FROM '.$db->prefix().'blokbay_invoice';
	$sql .= " WHERE inv_id = '".$db->escape($invId)."' AND entity = ".((int) $conf->entity);
	$res = $db->query($sql);
	if ($res && ($obj = $db->fetch_object($res))) {
		return $obj;
	}
	return null;
}

/**
 * Open Blokbay payment link of an invoice, or ''.
 *
 * @param DoliDB $db        Database
 * @param int    $fkFacture Invoice id
 * @return string
 */
function blokbay_open_url($db, $fkFacture)
{
	$row = blokbay_get_row($db, $fkFacture);
	return ($row && $row->status === 'open' && !empty($row->pay_url)) ? (string) $row->pay_url : '';
}

/**
 * Generate the PDF of the invoice again (link added or removed), like Dolibarr does after a change, unless
 * MAIN_DISABLE_PDF_AUTOUPDATE is set or the invoice has no document yet.
 *
 * @param Facture   $invoice Invoice
 * @param Translate $langs   Translations
 * @return void
 */
function blokbay_regen_pdf($invoice, $langs)
{
	global $conf, $db;
	$model = !empty($invoice->model_pdf) ? $invoice->model_pdf : getDolGlobalString('FACTURE_ADDON_PDF');
	if (getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE') || $model === '') {
		return;
	}
	$outputlangs = $langs;
	if (getDolGlobalInt('MAIN_MULTILANGS')) {
		if (empty($invoice->thirdparty)) {
			$invoice->fetch_thirdparty();
		}
		if (!empty($invoice->thirdparty->default_lang)) {
			$outputlangs = new Translate('', $conf);
			$outputlangs->setDefaultLang($invoice->thirdparty->default_lang);
			$outputlangs->loadLangs(array('main', 'bills', 'products'));
		}
	}
	$invoice->fetch($invoice->id);
	$res = $invoice->generateDocument($model, $outputlangs);
	if ($res <= 0) {
		dol_syslog('Blokbay: unable to generate the PDF of '.$invoice->ref.': '.$invoice->error, LOG_WARNING);
	}
}

/**
 * True when the invoice can get a Blokbay payment link: validated customer invoice (standard, deposit or situation) not paid yet.
 *
 * @param Facture $invoice Invoice
 * @return bool
 */
function blokbay_can_pay($invoice)
{
	return in_array((int) $invoice->type, array(Facture::TYPE_STANDARD, Facture::TYPE_DEPOSIT, Facture::TYPE_SITUATION), true)
		&& (int) $invoice->status === Facture::STATUS_VALIDATED && empty($invoice->paye);
}

/**
 * Create the Blokbay invoice for a Dolibarr invoice and save id, URL and status.
 *
 * @param DoliDB    $db      Database
 * @param Facture   $invoice Invoice
 * @param User      $user    User
 * @param Translate $langs   Translations
 * @return array{ok:bool,error:string,url:string}
 */
function blokbay_create_invoice($db, $invoice, $user, $langs)
{
	global $conf;
	if (!blokbay_is_configured()) {
		return array('ok' => false, 'error' => $langs->trans('BlokbayErrorNotConfigured'), 'url' => '');
	}
	if (!blokbay_can_pay($invoice)) {
		return array('ok' => false, 'error' => $langs->trans('BlokbayErrorNotPayable'), 'url' => '');
	}
	$row = blokbay_get_row($db, $invoice->id);
	if ($row && $row->status === 'open' && $row->pay_url) {
		return array('ok' => true, 'error' => '', 'url' => $row->pay_url);
	}
	$built = blokbay_build_payload($invoice, $langs);
	if ($built['error'] !== '') {
		return array('ok' => false, 'error' => $built['error'], 'url' => '');
	}
	$payload = $built['payload'];
	$attempt = ($row ? (int) $row->attempt : 0) + 1;
	$instance = substr(hash('sha256', (string) ($conf->file->instance_unique_id ?? '').'|'.DOL_MAIN_URL_ROOT), 0, 16);
	$idem = 'dolibarr:'.$instance.':'.((int) $conf->entity).':'.((int) $invoice->id).':'.$attempt;
	$res = blokbay_request('POST', '/api/v1/invoices', $payload, $idem);
	if ($res['status'] === 409 && $res['error'] === 'number' && isset($payload['number'])) {
		// The number is already used by another Blokbay invoice (e.g. a cancelled one): Blokbay numbers this one.
		unset($payload['number']);
		$res = blokbay_request('POST', '/api/v1/invoices', $payload, $idem.':auto');
	}
	if ($res['status'] < 200 || $res['status'] >= 300) {
		$map = array('no_wallet' => 'BlokbayErrorNoWallet', 'links' => 'BlokbayErrorLinks', 'amount' => 'BlokbayErrorAmount', 'currency' => 'BlokbayErrorCurrencyApi',
			'customer' => 'BlokbayErrorCustomer', 'network' => 'BlokbayErrorNetwork', 'http_401' => 'BlokbayErrorKey');
		$code = $res['status'] === 401 ? 'http_401' : $res['error'];
		$msg = isset($map[$code]) ? $langs->trans($map[$code]) : $langs->trans('BlokbayErrorApi', $res['status'], dol_escape_htmltag($res['message'] ?: $res['error']));
		return array('ok' => false, 'error' => $msg, 'url' => '');
	}
	$inv = $res['data'];
	if (!blokbay_valid_invoice($inv)) {
		return array('ok' => false, 'error' => $langs->trans('BlokbayErrorAnswer'), 'url' => '');
	}
	$vals = "inv_id = '".$db->escape($inv['id'])."', pay_url = '".$db->escape($inv['url'])."', number = '".$db->escape(substr((string) ($inv['number'] ?? ''), 0, 64))."'";
	$vals .= ", currency = '".$db->escape(substr((string) ($inv['currency'] ?? ''), 0, 3))."', total = '".$db->escape(substr((string) ($inv['total'] ?? ''), 0, 32))."'";
	$vals .= ", status = 'open', attempt = ".$attempt.', fk_paiement = NULL, tx = NULL';
	if ($row) {
		$ok = $db->query('UPDATE '.$db->prefix().'blokbay_invoice SET '.$vals.' WHERE rowid = '.((int) $row->rowid));
	} else {
		$ok = $db->query('INSERT INTO '.$db->prefix().'blokbay_invoice (entity, fk_facture, date_creation, inv_id, pay_url, number, currency, total, status, attempt)'
			.' VALUES ('.((int) $invoice->entity).', '.((int) $invoice->id).", '".$db->idate(dol_now())."', '".$db->escape($inv['id'])."', '".$db->escape($inv['url'])."', '"
			.$db->escape(substr((string) ($inv['number'] ?? ''), 0, 64))."', '".$db->escape(substr((string) ($inv['currency'] ?? ''), 0, 3))."', '"
			.$db->escape(substr((string) ($inv['total'] ?? ''), 0, 32))."', 'open', ".$attempt.')');
	}
	if (!$ok) {
		return array('ok' => false, 'error' => $db->lasterror(), 'url' => '');
	}
	blokbay_event($db, $invoice, $user, $langs->transnoentitiesnoconv('BlokbayEventCreated', $invoice->ref), $langs->transnoentitiesnoconv('BlokbayEventCreatedNote', $inv['url'], (string) ($inv['number'] ?? '')));
	blokbay_regen_pdf($invoice, $langs);
	return array('ok' => true, 'error' => '', 'url' => $inv['url']);
}

/**
 * Cancel the open Blokbay link of an invoice (POST /void) and remove it.
 *
 * @param DoliDB    $db      Database
 * @param Facture   $invoice Invoice
 * @param User      $user    User
 * @param Translate $langs   Translations
 * @param bool      $regen   Generate the PDF again without the link
 * @return array{ok:bool,error:string}
 */
function blokbay_void_invoice($db, $invoice, $user, $langs, $regen = true)
{
	$row = blokbay_get_row($db, $invoice->id);
	if (!$row || $row->status !== 'open') {
		return array('ok' => true, 'error' => '');
	}
	// The link is removed BEFORE the API call: the invoice.voided webhook that Blokbay sends meanwhile waits for this row
	// (or finds it already cancelled) instead of locking the invoice while a payment is being saved in the same transaction.
	$table = $db->prefix().'blokbay_invoice';
	$db->query('UPDATE '.$table." SET status = 'void', pay_url = NULL WHERE rowid = ".((int) $row->rowid)." AND status = 'open'");
	$restore = function () use ($db, $table, $row) {
		$db->query('UPDATE '.$table." SET status = 'open', pay_url = '".$db->escape($row->pay_url)."' WHERE rowid = ".((int) $row->rowid)." AND status = 'void'");
	};
	$res = blokbay_request('POST', '/api/v1/invoices/'.rawurlencode($row->inv_id).'/void', array());
	$status = $res['data']['status'] ?? '';
	if (($res['status'] < 200 || $res['status'] >= 300) && $res['status'] !== 409) {
		$restore();
		return array('ok' => false, 'error' => $langs->trans('BlokbayErrorApi', $res['status'], dol_escape_htmltag($res['message'] ?: $res['error'])));
	}
	if ($status === 'paid' || ($res['status'] === 409 && (blokbay_request('GET', '/api/v1/invoices/'.rawurlencode($row->inv_id))['data']['status'] ?? '') === 'paid')) {
		// Already paid on Blokbay: the link stays, the webhook registers the payment.
		$restore();
		return array('ok' => false, 'error' => $langs->trans('BlokbayErrorAlreadyPaid'));
	}
	blokbay_event($db, $invoice, $user, $langs->transnoentitiesnoconv('BlokbayEventVoided', $invoice->ref), '');
	if ($regen) {
		blokbay_regen_pdf($invoice, $langs);
	}
	return array('ok' => true, 'error' => '');
}

/**
 * Apply an invoice read again from the API (never the webhook body): paid registers the payment once, void removes the link.
 *
 * @param DoliDB    $db    Database
 * @param array     $inv   Invoice object from GET /api/v1/invoices/<id>
 * @param User      $user  User for the actions
 * @param Translate $langs Translations
 * @return string paid, void, duplicate, unknown invoice, ignored, retry
 */
function blokbay_apply_invoice($db, $inv, $user, $langs)
{
	global $conf;
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/paiement/class/paiement.class.php';

	$ref = blokbay_get_row_by_inv($db, $inv['id']);
	if (!$ref) {
		return 'unknown invoice';
	}
	$status = $inv['status'] ?? '';
	if ($status !== 'paid' && $status !== 'void') {
		return 'ignored';
	}

	// Short lock wait: when the invoice is being paid by hand in Dolibarr (the trigger cancels the link inside that transaction),
	// the webhook answers "retry" and Blokbay delivers it again later, instead of waiting on that transaction.
	if (in_array($db->type, array('mysql', 'mysqli'), true)) {
		$db->query('SET SESSION innodb_lock_wait_timeout = 5');
	}
	$db->begin();
	if ($db->type === 'pgsql') {
		$db->query("SET LOCAL lock_timeout = '5s'");
	}
	$sql = 'SELECT rowid FROM '.$db->prefix().'blokbay_invoice WHERE rowid = '.((int) $ref->rowid).' FOR UPDATE';
	if (!$db->query($sql)) {
		$db->rollback();
		return 'retry';
	}
	$row = blokbay_get_row($db, $ref->fk_facture);
	if (!$row || $row->inv_id !== $inv['id']) {
		$db->rollback();
		return 'unknown invoice';
	}
	$invoice = new Facture($db);
	if ($invoice->fetch((int) $row->fk_facture) <= 0) {
		$db->rollback();
		return 'unknown invoice';
	}

	if ($status === 'void') {
		if ($row->status !== 'open') {
			$db->rollback();
			return 'duplicate';
		}
		$db->query('UPDATE '.$db->prefix()."blokbay_invoice SET status = 'void', pay_url = NULL WHERE rowid = ".((int) $row->rowid));
		$db->commit();
		blokbay_event($db, $invoice, $user, $langs->transnoentitiesnoconv('BlokbayEventVoided', $invoice->ref), '');
		blokbay_regen_pdf($invoice, $langs);
		return 'void';
	}

	// paid
	if ($row->status === 'paid' || !empty($row->fk_paiement)) {
		$db->rollback();
		return 'duplicate';
	}
	$pay = is_array($inv['payment'] ?? null) ? $inv['payment'] : array();
	$tx = substr(preg_replace('/[^0-9A-Za-z]/', '', (string) ($pay['tx'] ?? '')), 0, 128);
	$note = $langs->transnoentitiesnoconv('BlokbayPaidNote', (string) ($inv['total'] ?? ''), (string) ($inv['currency'] ?? ''), (string) ($pay['coin'] ?? ''), (string) ($pay['network'] ?? ''), $tx);
	// Marked paid first (same transaction): the PDF generated again by the payment has no payment link.
	$db->query('UPDATE '.$db->prefix()."blokbay_invoice SET status = 'paid', tx = '".$db->escape($tx)."' WHERE rowid = ".((int) $row->rowid));
	list($currency, $mc) = blokbay_invoice_currency($invoice);
	$paiementId = 0;
	$warning = '';
	$remain = (float) $invoice->getRemainToPay($mc ? 1 : 0);
	$cur = blokbay_currencies();
	$dec = $cur[$currency] ?? 2;
	if (!empty($invoice->paye) || $remain <= 0) {
		$warning = 'already paid';
	} elseif (strtoupper((string) ($inv['currency'] ?? '')) !== $currency) {
		$warning = 'currency';
	} else {
		$bank = getDolGlobalInt('BLOKBAY_BANK_ACCOUNT');
		$paid = (float) price2num((string) ($inv['total'] ?? '0'), 'MT');
		$amount = (float) price2num(min($remain, $paid), 'MT');
		$paidAt = 0;
		$when = (string) ($pay['paid_at'] ?? ($inv['paid_at'] ?? ''));
		if ($when !== '' && ($ts = strtotime($when)) !== false) {
			$paidAt = $ts;
		}
		$paiement = new Paiement($db);
		$paiement->datepaye = $paidAt ?: dol_now();
		if ($mc) {
			$paiement->multicurrency_amounts = array($invoice->id => $amount);
		} else {
			$paiement->amounts = array($invoice->id => $amount);
		}
		$paiement->multicurrency_code = array($invoice->id => (string) $invoice->multicurrency_code);
		$paiement->multicurrency_tx = array($invoice->id => (float) $invoice->multicurrency_tx);
		$paiement->paiementid = (int) dol_getIdFromCode($db, getDolGlobalString('BLOKBAY_PAYMENT_MODE', 'VIR'), 'c_paiement', 'code', 'id', 1);
		$paiement->paiementcode = getDolGlobalString('BLOKBAY_PAYMENT_MODE', 'VIR');
		$paiement->num_payment = dol_trunc($tx, 50, 'right', 'UTF-8', 1);
		$paiement->note_public = $note;
		$paiement->note_private = $note;
		$paiement->ext_payment_id = $inv['id'].($tx !== '' ? ':'.$tx : '');
		$paiement->ext_payment_site = 'Blokbay';
		$GLOBALS['blokbay_processing'] = 1;
		$paiementId = $paiement->create($user, 1);
		if ($paiementId > 0 && $bank > 0) {
			$r = $paiement->addPaymentToBank($user, 'payment', '(CustomerInvoicePayment)', $bank, '', '');
			if ($r <= 0) {
				$paiementId = -1;
				$warning = $paiement->error;
			}
		} elseif ($paiementId > 0) {
			$warning = 'no bank account';
		}
		$GLOBALS['blokbay_processing'] = 0;
		if ($paiementId <= 0) {
			$db->rollback();
			dol_syslog('Blokbay: unable to register the payment of '.$invoice->ref.': '.$paiement->error.' '.implode(' ', (array) $paiement->errors).' '.$warning, LOG_ERR);
			return 'retry';
		}
	}
	$db->query('UPDATE '.$db->prefix()."blokbay_invoice SET status = 'paid', fk_paiement = ".($paiementId > 0 ? (int) $paiementId : 'NULL').", tx = '".$db->escape($tx)."' WHERE rowid = ".((int) $row->rowid));
	$db->commit();
	$label = $langs->transnoentitiesnoconv('BlokbayEventPaid', $invoice->ref);
	if ($warning !== '') {
		$note .= ' '.$langs->transnoentitiesnoconv('BlokbayPaidByHand', $warning);
	}
	blokbay_event($db, $invoice, $user, $label, $note);
	return 'paid';
}

/**
 * Add an event on the invoice (Agenda tab), when the Agenda module is on.
 *
 * @param DoliDB  $db      Database
 * @param Facture $invoice Invoice
 * @param User    $user    User
 * @param string  $label   Label
 * @param string  $note    Note
 * @return void
 */
function blokbay_event($db, $invoice, $user, $label, $note)
{
	if (!isModEnabled('agenda') || empty($user->id)) {
		dol_syslog('Blokbay: '.$label.' '.$note);
		return;
	}
	require_once DOL_DOCUMENT_ROOT.'/comm/action/class/actioncomm.class.php';
	$ev = new ActionComm($db);
	$ev->type_code = 'AC_OTH_AUTO';
	$ev->code = 'AC_BLOKBAY';
	$ev->label = dol_trunc($label, 128, 'right', 'UTF-8', 1);
	$ev->note_private = $note;
	$ev->datep = dol_now();
	$ev->datef = dol_now();
	$ev->percentage = -1;
	$ev->authorid = $user->id;
	$ev->userownerid = $user->id;
	$ev->socid = (int) $invoice->socid;
	$ev->fk_element = (int) $invoice->id;
	$ev->elementid = (int) $invoice->id;
	$ev->elementtype = 'invoice';
	$ev->create($user, 1);
}

/**
 * Text "Pay in USDC or USDT: <url>" for emails and PDF.
 *
 * @param Translate $outputlangs Translations
 * @param string    $url         Payment URL
 * @param bool      $html        HTML link
 * @return string
 */
function blokbay_pay_text($outputlangs, $url, $html)
{
	if (is_object($outputlangs)) {
		$outputlangs->load('blokbay@blokbay');
		$label = $outputlangs->transnoentitiesnoconv('BlokbayPayText');
	} else {
		$label = 'Pay in USDC or USDT:';
	}
	if ($html) {
		return dol_escape_htmltag($label).' <a href="'.dol_escape_htmltag($url).'">'.dol_escape_htmltag($url).'</a>';
	}
	return $label.' '.$url;
}
