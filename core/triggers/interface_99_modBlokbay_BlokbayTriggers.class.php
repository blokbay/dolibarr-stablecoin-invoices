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
 * \file    core/triggers/interface_99_modBlokbay_BlokbayTriggers.class.php
 * \ingroup blokbay
 * \brief   When an invoice with an open Blokbay link is paid another way, cancelled, set back to draft or deleted,
 *          the Blokbay invoice is cancelled too, so the customer cannot pay twice.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

/**
 * Triggers of the Blokbay module
 */
class InterfaceBlokbayTriggers extends DolibarrTriggers
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		parent::__construct($db);
		$this->family = 'financial';
		$this->description = 'Blokbay: cancels the payment link when the invoice is paid another way, cancelled, set back to draft or deleted.';
		$this->version = '1.0.0';
		$this->picto = 'blokbay@blokbay';
	}

	/**
	 * Run trigger
	 *
	 * @param string    $action Event code
	 * @param object    $object Object
	 * @param User      $user   User
	 * @param Translate $langs  Translations
	 * @param Conf      $conf   Configuration
	 * @return int 0 (never blocks the action)
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('blokbay') || !in_array($action, array('BILL_PAYED', 'BILL_CANCEL', 'BILL_UNVALIDATE', 'BILL_DELETE'), true) || empty($object->id)) {
			return 0;
		}
		require_once dol_buildpath('/blokbay/lib/blokbay.lib.php', 0);
		if (!empty($GLOBALS['blokbay_processing'])) {
			return 0; // payment registered by the Blokbay webhook
		}
		$row = blokbay_get_row($this->db, (int) $object->id);
		if ($row && $row->status === 'open') {
			$langs->load('blokbay@blokbay');
			$res = blokbay_void_invoice($this->db, $object, $user, $langs, false);
			if (!$res['ok']) {
				dol_syslog('Blokbay: unable to cancel the Blokbay invoice of '.$object->ref.': '.$res['error'], LOG_WARNING);
				if ($action !== 'BILL_DELETE') {
					setEventMessages($res['error'], null, 'warnings');
				}
			}
		}
		if ($action === 'BILL_DELETE' && $row) {
			$this->db->query('DELETE FROM '.$this->db->prefix().'blokbay_invoice WHERE rowid = '.((int) $row->rowid));
		}
		return 0;
	}
}
