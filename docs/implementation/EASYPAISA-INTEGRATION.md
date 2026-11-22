# Easypaisa Mobile Account integration

## Current implementation

- Implements the Easypaisa REST v4 Mobile Account (MA) flow, not OTC, credit-card, or other Easypaisa payment modes.
- Uses separate sandbox and production API hosts.
- Sends the store-specific merchant credentials in the HTTP `Credentials` header as Base64(`username:password`).
- Uses each store's own `storeId`, `accountNum`, username, and password. Username, password, and account number are encrypted through Junction's settings secret storage.
- Accepts PKR orders only and submits a two-decimal PKR amount.
- Sends a Mobile Account payment request to the customer's Pakistani mobile number and leaves the WooCommerce order pending until the authenticated status inquiry reports `PAID`.
- The IPN endpoint does not trust the incoming notification as proof of payment. It extracts the merchant account/order reference and then calls Easypaisa's authenticated `inquire-transaction` API before changing the order.
- A pending/unknown transaction is never re-initiated automatically. A later retry first checks the provider status; only a verified failed/blocked/expired/reversed state allows another attempt.
- A paid transaction must match the stored order amount, store ID, payment mode, and (when supplied) the mobile number. Mismatches move the order to manual review rather than marking it paid.

## Store-specific settings

- `EASYPAISA_USERNAME` — encrypted merchant API username
- `EASYPAISA_PASSWORD` — encrypted merchant API password
- `EASYPAISA_STORE_ID` — merchant store ID
- `EASYPAISA_ACCOUNT_NUMBER` — encrypted merchant Easypaisa account number used for status inquiry/IPN validation
- `EASYPAISA_SANDBOX` — `1` for sandbox, `0` for production

The legacy `EASYPAISA_HASH_KEY` setting is retained for compatibility with older configuration, but this Mobile Account REST v4 flow authenticates through the merchant credentials header.

## Merchant IPN setup

Configure the merchant portal's IPN/notification URL to:

`https://<store-domain>/?wc-api=easypaisa_ipn`

The IPN URL must send Easypaisa's status URL in the `url` (or `URL`) query parameter. The endpoint accepts only the Easypaisa staging/production hosts and the expected `order-status` path, checks that the merchant account number matches the store configuration, and then performs its own authenticated status inquiry. It does not fetch the supplied URL, so the notification cannot be used as an arbitrary server-side request.

## Required merchant sandbox acceptance

No merchant credentials were available for a live Easypaisa sandbox transaction. Before enabling this gateway for a store, validate the exact merchant API contract and run:

1. Successful Mobile Account initiation and customer approval.
2. Authenticated inquiry returning `PAID`, with the expected amount, store ID, payment mode, and mobile number.
3. Pending, failed, blocked, expired, and reversed transaction states.
4. Missing/forged IPN, malformed IPN URL, wrong merchant account number, and unknown order reference.
5. Network timeout after initiation, followed by status inquiry and retry prevention.
6. Duplicate IPN notifications and duplicate customer return/retry attempts.
7. Production merchant-account host/credentials and the actual callback URL configured in the merchant portal.

Do not claim production certification until these merchant-specific tests pass. The merchant's current Easypaisa integration pack takes precedence if its API fields, authentication header, status values, or IPN format differ from this implementation.
