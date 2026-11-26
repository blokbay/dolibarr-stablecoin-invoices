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
 * \file    class/actions_blokbay.class.php
 * \ingroup blokbay
 * \brief   Hooks: buttons and link on the customer invoice card, payment link in the invoice email and PDF.
 */

require_once __DIR__.'/../lib/blokbay.lib.php';

/**
 * Hooks of the Blokbay module (contexts invoicecard, formmail, pdfgeneration)
 */
class ActionsBlokbay
{
	/** @var DoliDB */
	public $db;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();
	/** @var array */
	public $results = array();
	/** @var string */
	public $resprints = '';

	/** @var array<int,string> Public notes changed for the PDF, restored after */
	private $notes = array();
	/** @var bool Guard of getFormMail */
	private static $inForm = false;

	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Is this the customer invoice card?
	 *
	 * @param array $parameters Hook parameters
	 * @return bool
	 */
	private function onInvoiceCard($parameters)
	{
		return in_array('invoicecard', explode(':', (string) ($parameters['context'] ?? '')), true);
	}

	/**
	 * Actions "Pay with Blokbay" (create the link) and "Cancel the Blokbay link".
	 *
	 * @param array       $parameters Hook parameters
	 * @param Facture     $object     Invoice
	 * @param string      $action     Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;
		if (!$this->onInvoiceCard($parameters) || !in_array($action, array('blokbay_create', 'blokbay_void'), true) || empty($object->id)) {
			return 0;
		}
		$langs->load('blokbay@blokbay');
		$back = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $object->id);
		if (GETPOST('token', 'alphanohtml') !== currentToken() || !$user->hasRight('facture', 'creer')) {
			setEventMessages($langs->trans('BlokbayErrorNotAllowed'), null, 'errors');
			header('Location: '.$back);
			exit;
		}
		if ($action === 'blokbay_create') {
			$res = blokbay_create_invoice($this->db, $object, $user, $langs);
			if ($res['ok']) {
				setEventMessages($langs->trans('BlokbayLinkCreated'), null, 'mesgs');
			} else {
				setEventMessages($res['error'], null, 'errors');
			}
		} else {
			$res = blokbay_void_invoice($this->db, $object, $user, $langs);
			if ($res['ok']) {
				setEventMessages($langs->trans('BlokbayLinkVoided'), null, 'mesgs');
			} else {
				setEventMessages($res['error'], null, 'errors');
			}
		}
		header('Location: '.$back);
		exit;
	}

	/**
	 * Buttons on the invoice card: "Pay with Blokbay" (creates the link), "Pay in USDC/USDT" (opens it) and cancel.
	 *
	 * @param array       $parameters  Hook parameters
	 * @param Facture     $object      Invoice
	 * @param string      $action      Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $user, $langs;
		if (!$this->onInvoiceCard($parameters) || empty($object->id) || !blokbay_can_pay($object)) {
			return 0;
		}
		$langs->load('blokbay@blokbay');
		$url = blokbay_open_url($this->db, (int) $object->id);
		$self = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $object->id).'&token='.newToken();
		$canwrite = $user->hasRight('facture', 'creer');
		if ($url !== '') {
			print dolGetButtonAction('', $langs->trans('BlokbayPayButton'), 'default', $url, 'blokbay-pay', 1, array('attr' => array('target' => '_blank', 'rel' => 'noopener')));
			print dolGetButtonAction('', $langs->trans('BlokbayVoidButton'), 'delete', $self.'&action=blokbay_void', 'blokbay-void', $canwrite);
		} elseif (!blokbay_is_configured()) {
			print dolGetButtonAction($langs->trans('BlokbayErrorNotConfigured'), $langs->trans('BlokbayCreateButton'), 'default', '#', 'blokbay-create', 0);
		} else {
			list($currency) = blokbay_invoice_currency($object);
			$ok = isset(blokbay_currencies()[$currency]);
			print dolGetButtonAction($ok ? '' : $langs->trans('BlokbayErrorCurrency', $currency, implode(', ', array_keys(blokbay_currencies()))),
				$langs->trans('BlokbayCreateButton'), 'default', $self.'&action=blokbay_create', 'blokbay-create', $canwrite && $ok);
		}
		return 0;
	}

	/**
	 * Row "Blokbay payment link" in the fields of the invoice card.
	 *
	 * @param array       $parameters  Hook parameters
	 * @param Facture     $object      Invoice
	 * @param string      $action      Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;
		if (!$this->onInvoiceCard($parameters) || empty($object->id) || $action === 'create' || !is_object($object) || $object->element !== 'facture') {
			return 0;
		}
		$row = blokbay_get_row($this->db, (int) $object->id);
		if (!$row) {
			return 0;
		}
		$langs->load('blokbay@blokbay');
		$labels = array('open' => 'BlokbayStatusOpen', 'paid' => 'BlokbayStatusPaid', 'void' => 'BlokbayStatusVoid');
		$out = '<tr class="blokbay-row"><td class="titlefield">'.img_picto('', 'blokbay@blokbay', 'class="pictofixedwidth"').dol_escape_htmltag($langs->trans('BlokbayPaymentLink')).'</td><td id="blokbay-link">';
		if ($row->status === 'open' && $row->pay_url) {
			$out .= '<a href="'.dol_escape_htmltag($row->pay_url).'" target="_blank" rel="noopener" class="butAction small">'.dol_escape_htmltag($langs->trans('BlokbayPayButton')).'</a> ';
			$out .= '<a href="'.dol_escape_htmltag($row->pay_url).'" target="_blank" rel="noopener">'.dol_escape_htmltag($row->pay_url).'</a> ';
			$out .= '<span class="opacitymedium">'.dol_escape_htmltag($langs->trans('BlokbayKeyEmailPdf')).'</span>';
		}
		$out .= ' <span class="badge badge-status'.($row->status === 'paid' ? '4' : ($row->status === 'open' ? '1' : '9')).'" id="blokbay-status">'.dol_escape_htmltag($langs->trans($labels[$row->status] ?? 'BlokbayStatusVoid')).'</span>';
		if ($row->number) {
			$out .= ' <span class="opacitymedium">'.dol_escape_htmltag($row->number).'</span>';
		}
		$out .= '</td></tr>';
		$this->resprints = $out;
		return 0;
	}

	/**
	 * Email form of an invoice: adds the payment link to the proposed message (unless the template already has __BLOKBAY_PAY_URL__).
	 * The form is built once by Dolibarr, then the link is inserted in the message before "Sincerely" (or at the end).
	 *
	 * @param array    $parameters  Hook parameters
	 * @param FormMail $object      Email form
	 * @param string   $action      Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function getFormMail($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $conf;
		if (self::$inForm || !getDolGlobalInt('BLOKBAY_ADD_TO_EMAIL', 1) || !is_object($object) || empty($object->param['models'])
			|| !in_array($object->param['models'], array('facture_send', 'facture_relance', 'invoice'), true) || empty($object->param['id'])) {
			return 0;
		}
		if (GETPOSTISSET('message') && !GETPOST('modelselected')) {
			return 0; // message already written by the user
		}
		$url = blokbay_open_url($this->db, (int) $object->param['id']);
		if ($url === '') {
			return 0;
		}
		self::$inForm = true;
		$html = $object->get_form($parameters['addfileaction'] ?? 'addfile', $parameters['removefileaction'] ?? 'removefile');
		self::$inForm = false;
		if (!is_string($html) || $html === '') {
			return 0;
		}
		$outputlangs = $langs;
		if (getDolGlobalInt('MAIN_MULTILANGS') && !empty($object->param['langsmodels'])) {
			$outputlangs = new Translate('', $conf);
			$outputlangs->setDefaultLang($object->param['langsmodels']);
		}
		$outputlangs->loadLangs(array('main', 'other', 'blokbay@blokbay'));
		$new = preg_replace_callback('#(<textarea\b[^>]*\bname="message"[^>]*>)(.*?)(</textarea>)#s', function ($m) use ($url, $outputlangs) {
			$content = html_entity_decode($m[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if (strpos($content, $url) !== false) {
				return $m[0];
			}
			$ishtml = dol_textishtml($content);
			$block = blokbay_pay_text($outputlangs, $url, $ishtml);
			$block = $ishtml ? '<p>'.$block.'</p>' : $block."\n\n";
			$sincerely = $outputlangs->transnoentitiesnoconv('Sincerely');
			$pos = $sincerely !== '' ? strrpos($content, $sincerely) : false;
			if ($pos !== false) {
				if ($ishtml) {
					// insert before the paragraph or line holding "Sincerely"
					$start = max((int) strrpos(substr($content, 0, $pos), '<p'), (int) strrpos(substr($content, 0, $pos), '<br'));
					$start = $start > 0 ? $start : $pos;
					$content = substr($content, 0, $start).$block.substr($content, $start);
				} else {
					$content = substr($content, 0, $pos).$block.substr($content, $pos);
				}
			} else {
				$content = rtrim($content).($ishtml ? '' : "\n\n").$block;
			}
			return $m[1].htmlspecialchars($content, ENT_COMPAT | ENT_HTML5, 'UTF-8').$m[3];
		}, $html, 1);
		$this->resprints = $new === null ? $html : $new;
		return 1;
	}

	/**
	 * Invoice PDF: the payment link is added to the public note for this generation only (unless the note already has the key).
	 *
	 * @param array       $parameters  Hook parameters
	 * @param Facture     $object      Invoice
	 * @param string      $action      Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function beforePDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		if (!getDolGlobalInt('BLOKBAY_ADD_TO_PDF', 1) || !is_object($object) || ($object->element ?? '') !== 'facture' || empty($object->id)) {
			return 0;
		}
		$url = blokbay_open_url($this->db, (int) $object->id);
		$note = (string) $object->note_public;
		if ($url === '' || strpos($note, '__BLOKBAY_PAY') !== false || strpos($note, $url) !== false) {
			return 0;
		}
		$outputlangs = $parameters['outputlangs'] ?? null;
		$this->notes[(int) $object->id] = $object->note_public;
		$object->note_public = dol_concatdesc($note, '<b>'.blokbay_pay_text($outputlangs, $url, true).'</b>');
		return 0;
	}

	/**
	 * Restore the public note changed by beforePDFCreation.
	 *
	 * @param array       $parameters  Hook parameters
	 * @param object      $object      PDF model
	 * @param string      $action      Action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function afterPDFCreation($parameters, &$object, &$action, $hookmanager)
	{
		$inv = $parameters['object'] ?? null;
		if (is_object($inv) && isset($this->notes[(int) $inv->id])) {
			$inv->note_public = $this->notes[(int) $inv->id];
			unset($this->notes[(int) $inv->id]);
		}
		return 0;
	}
}
