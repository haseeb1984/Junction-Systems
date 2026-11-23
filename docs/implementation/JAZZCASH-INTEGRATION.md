# JazzCash Mobile Wallet integration

## Current implementation

- Uses the JazzCash Mobile Wallet API 2.0 endpoint, with separate sandbox and production URLs.
- Accepts PKR orders only. The order amount is converted to paisa without floating-point truncation and sent as a zero-padded minor-unit amount.
- Requires the customer's Pakistani mobile number and the last six digits of the CNIC. The CNIC fragment is submitted to JazzCash for the transaction and is not written to order metadata, logs, or Junction settings.
- Accepts local `03XXXXXXXXX`, national `3XXXXXXXXX`, and international `923XXXXXXXXX` mobile formats and normalizes them to the JazzCash local format.
- Generates and verifies HMAC-SHA256 signatures over sorted PP fields using the configured integrity key and the documented ISO-8859-1 message encoding.
- Sends `pp_ReturnURL` to the WooCommerce API callback endpoint `/?wc-api=jazzcash_callback`.
- Verifies the immediate provider response and callback signature, order reference, transaction reference, amount, merchant, currency, and transaction date before completing an order.
- Treats provider response `000` as confirmed and `157` as pending. Signed declines are marked failed.
- A network timeout, unreadable response, or signature mismatch is treated as unknown/pending. A second transaction is blocked while the first is unresolved, including after its local expiry time, until the provider reports a signed failure or an operator reconciles the transaction. This deliberately avoids double-charging customers when the first request may have reached JazzCash but its response was lost.

## Store-specific configuration

Each customer installation must use its own merchant configuration:

- `JAZZCASH_MERCHANT_ID`
- `JAZZCASH_PASSWORD`
- `JAZZCASH_INTEGRITY_KEY`
- `JAZZCASH_SANDBOX`

Do not share merchant credentials between stores. Keep credentials out of logs, exports, backups, and customer-visible settings.

## Required provider/sandbox acceptance before production

This repository change is source-level only. No merchant credentials were available for a live sandbox transaction. Before enabling the gateway for a store, validate all of the following against that store's JazzCash merchant account and the exact API contract provisioned by JazzCash:

1. Confirm the account is provisioned for the `/API/2.0/Purchase/DoMWalletTransaction` mobile-wallet operation and that `pp_Version=2.0`, `pp_TxnType=MWALLET`, `pp_MobileNumber`, `pp_CNIC`, and the amount format are accepted for that merchant.
2. Confirm the provider's required CNIC representation (last six digits), mobile-number format, amount padding, and HMAC encoding against the merchant's current integration pack.
3. Register/allowlist the exact callback URL `https://<store-domain>/?wc-api=jazzcash_callback` with JazzCash if the merchant account requires it.
4. Run successful, declined, pending, timeout, duplicate-callback, invalid-signature, amount-mismatch, wrong-merchant, and late-callback scenarios in sandbox.
5. Confirm the callback actually reaches the WooCommerce API endpoint and contains the fields used by the signature and transaction checks.
6. Confirm the merchant's status-inquiry/reconciliation process for unknown transactions. The current implementation intentionally does not initiate a second charge automatically while the first transaction remains unresolved.

Do not claim JazzCash production certification until the merchant-specific sandbox acceptance has passed. If JazzCash's provisioned API variant differs from the fields above, adapt the request and signature contract to that merchant's official integration pack before enabling live payments.
