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
 * \file    admin/setup.php
 * \ingroup blokbay
 * \brief   Settings of the Blokbay module: API URL, secret key, webhook secret, bank account, webhook URL to copy.
 */

$res = 0;
if (!$res && !empty($_SERVER['CONTEXT_DOCUMENT_ROOT'])) {
	$res = @include $_SERVER['CONTEXT_DOCUMENT_ROOT'].'/main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../main.inc.php')) {
	$res = @include __DIR__.'/../../main.inc.php';
}
if (!$res && file_exists(__DIR__.'/../../../main.inc.php')) {
	$res = @include __DIR__.'/../../../main.inc.php';
}
if (!$res) {
	die('Include of main fails');
}
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.form.class.php';
require_once __DIR__.'/../lib/blokbay.lib.php';

$langs->loadLangs(array('admin', 'banks', 'bills', 'blokbay@blokbay'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$form = new Form($db);
$error = 0;

if ($action === 'save') {
	$apiUrl = trim(GETPOST('BLOKBAY_API_URL', 'alphanohtml'));
	$key = trim(GETPOST('BLOKBAY_SECRET_KEY', 'password'));
	$whsec = trim(GETPOST('BLOKBAY_WEBHOOK_SECRET', 'password'));
	if ($apiUrl === '') {
		$apiUrl = 'https://blokbay.com';
	}
	if (!preg_match('~^https?://[^\s/?#]+(:\d+)?(/[^\s?#]*)?$~i', $apiUrl)) {
		setEventMessages($langs->trans('BlokbayErrorApiUrl'), null, 'errors');
		$error++;
	}
	if ($key !== '' && !preg_match('/^sk_live_[A-Za-z0-9]{24,64}$/', $key)) {
		setEventMessages($langs->trans('BlokbayErrorKeyFormat'), null, 'errors');
		$error++;
	}
	if ($whsec !== '' && !preg_match('/^whsec_[A-Za-z0-9_-]{16,200}$/', $whsec)) {
		setEventMessages($langs->trans('BlokbayErrorSecretFormat'), null, 'errors');
		$error++;
	}
	if (!$error) {
		dolibarr_set_const($db, 'BLOKBAY_API_URL', rtrim($apiUrl, '/'), 'chaine', 0, '', $conf->entity);
		if ($key !== '') {
			dolibarr_set_const($db, 'BLOKBAY_SECRET_KEY', $key, 'chaine', 0, '', $conf->entity);
		}
		if ($whsec !== '') {
			dolibarr_set_const($db, 'BLOKBAY_WEBHOOK_SECRET', $whsec, 'chaine', 0, '', $conf->entity);
		}
		dolibarr_set_const($db, 'BLOKBAY_BANK_ACCOUNT', (string) GETPOSTINT('BLOKBAY_BANK_ACCOUNT'), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'BLOKBAY_PAYMENT_MODE', GETPOST('BLOKBAY_PAYMENT_MODE', 'aZ09') ?: 'VIR', 'chaine', 0, '', $conf->entity);
		$uid = GETPOSTINT('BLOKBAY_USER_ACCOUNT_FOR_ACTIONS');
		dolibarr_set_const($db, 'BLOKBAY_USER_ACCOUNT_FOR_ACTIONS', (string) ($uid > 0 ? $uid : $user->id), 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'BLOKBAY_ADD_TO_PDF', GETPOSTINT('BLOKBAY_ADD_TO_PDF') ? '1' : '0', 'chaine', 0, '', $conf->entity);
		dolibarr_set_const($db, 'BLOKBAY_ADD_TO_EMAIL', GETPOSTINT('BLOKBAY_ADD_TO_EMAIL') ? '1' : '0', 'chaine', 0, '', $conf->entity);
		setEventMessages($langs->trans('SetupSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}
}
if ($action === 'test') {
	$r = blokbay_request('GET', '/api/v1/invoices?external_ref='.rawurlencode('dolibarr:connection-test'));
	if ($r['status'] === 200) {
		setEventMessages($langs->trans('BlokbayTestOk'), null, 'mesgs');
	} elseif ($r['status'] === 401) {
		setEventMessages($langs->trans('BlokbayErrorKey'), null, 'errors');
	} else {
		setEventMessages($langs->trans('BlokbayErrorApi', $r['status'], dol_escape_htmltag($r['message'] ?: $r['error'])), null, 'errors');
	}
	header('Location: '.$_SERVER['PHP_SELF']);
	exit;
}

$saved = function ($value, $prefixLen) use ($langs) {
	if ($value === '') {
		return '<span class="opacitymedium">'.dol_escape_htmltag($langs->trans('BlokbayNotSaved')).'</span>';
	}
	return '<span class="badge badge-status4" id="'.($prefixLen === 8 ? 'blokbay-key-saved' : 'blokbay-secret-saved').'">'.dol_escape_htmltag($langs->trans('BlokbaySavedEnding', substr($value, -4))).'</span>';
};

llxHeader('', $langs->trans('BlokbaySetup'), '', '', 0, 0, '', '', '', 'mod-blokbay page-admin-setup');

$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans('BackToModuleList').'</a>';
print load_fiche_titre($langs->trans('BlokbaySetup'), $linkback, 'blokbay@blokbay');
print '<span class="opacitymedium">'.$langs->trans('BlokbaySetupIntro').'</span><br><br>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" autocomplete="off">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="action" value="save">';
print '<table class="noborder centpercent">';
print '<tr class="liste_titre"><td class="titlefieldcreate">'.$langs->trans('Parameter').'</td><td>'.$langs->trans('Value').'</td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('BlokbayApiUrl').'</td><td><input type="url" class="minwidth300" name="BLOKBAY_API_URL" value="'.dol_escape_htmltag(blokbay_api_base()).'"><br><span class="opacitymedium small">'.$langs->trans('BlokbayApiUrlHelp').'</span></td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('BlokbaySecretKey').'</td><td><input type="password" class="minwidth300" name="BLOKBAY_SECRET_KEY" value="" placeholder="sk_live_..." autocomplete="new-password"> '.$saved(blokbay_secret_key(), 8);
print '<br><span class="opacitymedium small">'.$langs->trans('BlokbaySecretKeyHelp').'</span></td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('BlokbayWebhookSecret').'</td><td><input type="password" class="minwidth300" name="BLOKBAY_WEBHOOK_SECRET" value="" placeholder="whsec_..." autocomplete="new-password"> '.$saved(trim(getDolGlobalString('BLOKBAY_WEBHOOK_SECRET')), 6);
print '<br><span class="opacitymedium small">'.$langs->trans('BlokbayWebhookSecretHelp').'</span></td></tr>';

$hook = blokbay_webhook_url();
print '<tr class="oddeven"><td>'.$langs->trans('BlokbayWebhookUrl').'</td><td><input type="text" class="minwidth500" id="blokbay-webhook-url" readonly value="'.dol_escape_htmltag($hook).'" onclick="this.select()"> '.showValueWithClipboardCPButton($hook, 0, 'none');
print '<br><span class="opacitymedium small">'.$langs->trans('BlokbayWebhookUrlHelp').'</span></td></tr>';

print '<tr class="oddeven"><td class="fieldrequired">'.$langs->trans('BlokbayBankAccount').'</td><td>';
print $form->select_comptes(getDolGlobalInt('BLOKBAY_BANK_ACCOUNT'), 'BLOKBAY_BANK_ACCOUNT', 0, '', 1, '', 1, 'minwidth300', 1);
print '<br><span class="opacitymedium small">'.$langs->trans('BlokbayBankAccountHelp').'</span></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('BlokbayPaymentMode').'</td><td>';
print $form->select_types_paiements(getDolGlobalString('BLOKBAY_PAYMENT_MODE', 'VIR'), 'BLOKBAY_PAYMENT_MODE', 'CRDT', 2, 0, 1, 0, 1, 'minwidth200', 1);
print '</td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('BlokbayUserForActions').'</td><td>';
print $form->select_dolusers(getDolGlobalInt('BLOKBAY_USER_ACCOUNT_FOR_ACTIONS', $user->id), 'BLOKBAY_USER_ACCOUNT_FOR_ACTIONS', 0, null, 0, '', '', '0', 0, 0, '', 0, '', 'minwidth200');
print '<br><span class="opacitymedium small">'.$langs->trans('BlokbayUserForActionsHelp').'</span></td></tr>';

print '<tr class="oddeven"><td>'.$langs->trans('BlokbayAddToPdf').'</td><td>'.$form->selectyesno('BLOKBAY_ADD_TO_PDF', getDolGlobalInt('BLOKBAY_ADD_TO_PDF', 1), 1).'</td></tr>';
print '<tr class="oddeven"><td>'.$langs->trans('BlokbayAddToEmail').'</td><td>'.$form->selectyesno('BLOKBAY_ADD_TO_EMAIL', getDolGlobalInt('BLOKBAY_ADD_TO_EMAIL', 1), 1);
print '<br><span class="opacitymedium small">'.$langs->trans('BlokbaySubstitutionHelp').'</span></td></tr>';

print '</table>';
print '<div class="center"><input type="submit" class="button button-save" value="'.dol_escape_htmltag($langs->trans('Save')).'"></div>';
print '</form>';

if (blokbay_is_configured()) {
	print '<br><div class="center"><a class="butAction" id="blokbay-test" href="'.$_SERVER['PHP_SELF'].'?action=test&token='.newToken().'">'.$langs->trans('BlokbayTestConnection').'</a></div>';
}
print '<br><span class="opacitymedium">'.$langs->trans('BlokbayDocs').' <a href="https://blokbay.com/docs/api#invoices" target="_blank" rel="noopener">https://blokbay.com/docs/api#invoices</a></span>';

llxFooter();
$db->close();
