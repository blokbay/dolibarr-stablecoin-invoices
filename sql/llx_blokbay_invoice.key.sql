-- Copyright (C) 2026 Blokbay <mail@blokbay.com>
-- License GPL-3.0-or-later (https://www.gnu.org/licenses/gpl-3.0.html)

ALTER TABLE llx_blokbay_invoice ADD UNIQUE INDEX uk_blokbay_invoice_facture (fk_facture);
ALTER TABLE llx_blokbay_invoice ADD INDEX idx_blokbay_invoice_inv_id (inv_id);
