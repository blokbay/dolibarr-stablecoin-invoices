-- Copyright (C) 2026 Blokbay <mail@blokbay.com>
-- License GPL-3.0-or-later (https://www.gnu.org/licenses/gpl-3.0.html)
--
-- One row per Dolibarr customer invoice paid with Blokbay: Blokbay invoice id, payment link and status.

CREATE TABLE llx_blokbay_invoice(
	rowid integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity integer DEFAULT 1 NOT NULL,
	fk_facture integer NOT NULL,
	inv_id varchar(64),
	pay_url varchar(255),
	number varchar(64),
	currency varchar(3),
	total varchar(32),
	status varchar(16),
	attempt integer DEFAULT 0 NOT NULL,
	fk_paiement integer,
	tx varchar(128),
	date_creation datetime,
	tms timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
