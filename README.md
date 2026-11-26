# Blokbay for Dolibarr: crypto invoices in USDC and USDT

Dolibarr USDC and USDT invoices: accept crypto payments on your customer invoices, straight to your own wallet (non-custodial). The
module adds a **Pay with Blokbay** button to validated customer invoices: it creates a
Blokbay invoice with the same lines, taxes and total, and puts the payment link on the invoice card, in the invoice PDF and in the
invoice email. Your customer pays the stablecoin invoice from a wallet or an exchange on Ethereum, Solana, Base, Polygon, Arbitrum
or BNB Chain. When your customer pays, the signed Blokbay webhook registers the payment on the bank account you choose and the
invoice is classified paid.

- Author: Blokbay, mail@blokbay.com, https://blokbay.com
- API documentation: https://blokbay.com/docs/api#invoices
- License: GPL-3.0-or-later (see LICENSE)
- Module number: 530877
- Dolibarr 19 or later (tested with Dolibarr 24.0.4 on PHP 8.6, MariaDB)
- 16 languages: English, Italian, French, German, Spanish, Portuguese, Russian, Chinese, Japanese, Hindi, Korean, Turkish,
  Indonesian, Arabic, Vietnamese, Polish

## Installation

1. In Dolibarr open **Home > Setup > Modules/Applications > Deploy/install external app/module** and upload
   `module_blokbay-1.0.0.zip` (or unzip it so that the folder `blokbay` is in `htdocs/custom`).
2. In **Modules/Applications** enable **Blokbay** (family "Financial modules"). Invoices and Banks are enabled with it.
3. Open the settings of the module: paste the secret key `sk_live_...` and the webhook signing secret `whsec_...` from your Blokbay
   panel, choose the bank account for the payments (for example a new account named "Stablecoins") and save.
4. Copy the **Webhook URL** shown on the settings page in the webhook settings of your Blokbay panel.

## How it works

1. On a validated customer invoice that is not paid yet, click **Pay with Blokbay**. The module calls `POST /api/v1/invoices` from
   your server (never from the browser) with `send: false`: Dolibarr sends its own invoice email. Each line goes with its net amount
   (discounts included) and the taxes as one line, so the Blokbay total is exactly the Dolibarr total. A partly paid invoice goes as
   one line with the amount still to pay. Web addresses are removed from the texts, as Blokbay refuses them.
2. The link appears on the invoice card (button **Pay in USDC/USDT** and a "Blokbay payment link" row), in the PDF (added to the
   public note when the PDF is generated) and in the proposed message of the invoice email. In email templates, public notes and
   ODT templates you can also use `__BLOKBAY_PAY_URL__` (the link) and `__BLOKBAY_PAY_TEXT_AND_URL__` (text and link).
3. The webhook (`/custom/blokbay/public/webhook.php`) checks the `Blokbay-Signature` header (HMAC-SHA256, 5 minutes of tolerance,
   constant time comparison), then reads the invoice again with `GET /api/v1/invoices/<id>` and acts only on that status:
   - `paid`: one payment (class Paiement) for the amount left to pay, on the chosen bank account and payment method, with the
     transaction hash as number; the invoice is classified paid. The same event delivered again does not create a second payment.
   - `void`: the link is removed from the invoice, the PDF and the emails.
   A wrong signature gets 400; unknown or repeated events get 200.
4. If the invoice is paid another way, cancelled, set back to draft or deleted, the Blokbay invoice is cancelled too, so your
   customer cannot pay twice. **Cancel the Blokbay link** on the card does the same by hand.

Currencies: the currency of the invoice (with the Multicurrency module) or of your company, among USD, EUR, GBP, CHF, JPY, CAD, AUD,
SGD, HKD and AED. With another currency the button is disabled. The customer (or its billing contact) needs an email address.

The secret key and the webhook secret are stored encrypted in the Dolibarr settings and are never shown again after saving.

## Settings

| Setting | Constant |
| --- | --- |
| API URL (default https://blokbay.com) | `BLOKBAY_API_URL` |
| Secret key | `BLOKBAY_SECRET_KEY` (encrypted) |
| Webhook signing secret | `BLOKBAY_WEBHOOK_SECRET` (encrypted) |
| Bank account for the payments | `BLOKBAY_BANK_ACCOUNT` |
| Payment method (default bank transfer) | `BLOKBAY_PAYMENT_MODE` |
| User for automatic payments | `BLOKBAY_USER_ACCOUNT_FOR_ACTIONS` |
| Link in the PDF / in the email | `BLOKBAY_ADD_TO_PDF`, `BLOKBAY_ADD_TO_EMAIL` |

With several entities (Multicompany), each entity has its own settings and its webhook URL ends with `?entity=<id>`.

Keywords: Dolibarr USDC, Dolibarr crypto payments, crypto invoice, stablecoin invoice, USDC invoice, USDT invoice, invoice payment link, accept crypto, B2B crypto payments, non-custodial, Web3 invoicing, blockchain invoice, accounts receivable, international payments.
