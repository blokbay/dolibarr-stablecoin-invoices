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
 * \file    core/modules/modBlokbay.class.php
 * \ingroup blokbay
 * \brief   Descriptor of the Blokbay module: customer invoices payable in USDC or USDT.
 */
include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

/**
 * Description and activation class for module Blokbay
 */
class modBlokbay extends DolibarrModules
{
	/**
	 * Constructor
	 *
	 * @param DoliDB $db Database handler
	 */
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 530877;
		$this->rights_class = 'blokbay';
		$this->family = 'financial';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'BlokbayDescription';
		$this->descriptionlong = 'BlokbayDescriptionLong';
		$this->editor_name = 'Blokbay';
		$this->editor_url = 'https://blokbay.com';
		$this->editor_squarred_logo = 'blokbay.png@blokbay';
		$this->version = '1.0.0';
		$this->url_last_version = '';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'blokbay@blokbay';

		$this->module_parts = array(
			'triggers' => 1,
			'substitutions' => 1,
			'hooks' => array('data' => array('invoicecard', 'formmail', 'pdfgeneration'), 'entity' => '0'),
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@blokbay');
		$this->depends = array('modFacture', 'modBanque');
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('blokbay@blokbay');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(19, 0);
		$this->need_javascript_ajax = 0;

		$this->const = array(
			array('BLOKBAY_API_URL', 'chaine', 'https://blokbay.com', 'Blokbay API URL', 0, 'current', 0),
			array('BLOKBAY_PAYMENT_MODE', 'chaine', 'VIR', 'Payment method code used for Blokbay payments', 0, 'current', 0),
			array('BLOKBAY_ADD_TO_PDF', 'chaine', '1', 'Add the payment link to the invoice PDF', 0, 'current', 0),
			array('BLOKBAY_ADD_TO_EMAIL', 'chaine', '1', 'Add the payment link to the invoice email', 0, 'current', 0),
		);

		if (!isModEnabled('blokbay')) {
			$conf->blokbay = new stdClass();
			$conf->blokbay->enabled = 0;
		}

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();
	}

	/**
	 * Called when the module is enabled: creates the table of the module.
	 *
	 * @param string $options Options when enabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function init($options = '')
	{
		$result = $this->_load_tables('/blokbay/sql/');
		if ($result < 0) {
			return -1;
		}
		$this->remove($options);

		return $this->_init(array(), $options);
	}

	/**
	 * Called when the module is disabled. The table and the settings are kept.
	 *
	 * @param string $options Options when disabling module ('', 'noboxes')
	 * @return int 1 if OK, 0 if KO
	 */
	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
