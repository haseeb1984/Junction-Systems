# Phase B — New customer and platform requirements

## Implemented in the Junction client

- Package entitlements remain centrally authoritative; local store toggles cannot grant unlicensed features.
- The client now uses a Groq OpenAI-compatible provider. API keys and the selected model are not editable by store owners or employees.
- The central license response can provision a per-install Groq key pool and a final resolved key limit. The resolved limit is expected from the central service after it applies the package maximum and any platform-owner per-customer override.
- Groq keys are carried inside the encrypted license cache, limited to the centrally supplied count, rotated across requests, and skipped during rate-limit, invalid-key, network, and provider-outage cooldowns.
- Provider configuration refreshes every five minutes while online. A cached Groq pool is used during a central-service outage for no more than 24 hours; after that AI fails closed.
- Dukaan AI now uses the centrally selected provider model. It treats store/product data as untrusted facts, never invents product/stock/price/order details, and cannot cancel orders, approve refunds/returns, promise compensation, or claim a support ticket was created. Those requests require human support.
- AI product discovery now searches the full published WooCommerce catalogue (including SKU and variation attributes), supports hard budget limits in English/Roman Urdu, returns exact variant SKU/link/price/stock data, and offers same-category available alternatives when the requested item is out of stock. Exact stock quantities are included only when the store owner enables WooCommerce's stock-quantity display setting. Chat history is isolated per browser session and retains the latest 20 messages in chronological order.
- Customer-facing stock remains controlled by WooCommerce's stock display setting; the default is in-stock/out-of-stock rather than exact quantities. Combined stock across POS locations remains the online-store sellable quantity.

## External platform integration required

The central licensing service and platform-owner dashboard are not present in this repository. To activate the Groq pool in production, that service must implement the payload in [GROQ-PROVIDER-CONTRACT.md](GROQ-PROVIDER-CONTRACT.md), including:
- per-package maximum key count;
- per-customer override;
- that installation's customer-provided Groq keys;
- centrally selected Groq model;
- domain-bound delivery to the matching installation only.

Until that payload is deployed and verified, the client deliberately reports Groq as unconfigured. Do not place Groq keys in store-admin settings or a shared global option.

## Hosting, backup, billing, and subscription controls

The agreed requirements for hosting-owned automated backups, customer-initiated encrypted exports, backup retention/restore, central invoices, monthly/yearly subscriptions, seven-day grace, suspension, payment verification, and automatic reactivation require the central hosting/billing control plane. No such control-plane repository or API contract is included in this project checkout. The client theme must not simulate successful backups, payments, license changes, or reactivation without authoritative confirmation from that service.

The client-side migration runner remains fail-closed until the hosting integration confirms a recent full database and website-files backup through `pkstore_migration_backup_confirmed`. This is intentional; do not bypass the backup gate to make an upgrade appear successful.
