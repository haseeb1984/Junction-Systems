# PRAL / FBR Digital Invoicing Integration

## Official sources used

- PRAL/FBR *Technical Documentation for DI API*, version 1.12: https://download1.fbr.gov.pk/Docs/20257301172130815TechnicalDocumentationforDIAPIV1.12.pdf
- PRAL/FBR *Digital Invoicing User Manual*, version 1.6 (2025–2026): https://dicrm.pral.com.pk/assets/docs/Manuals/Digital%20Invoicing%20User%20Manual%20-%20v1.6.pdf

The client follows these documents, not guessed third-party endpoint conventions.

## Per-customer credential contract

The central licensing service must return the following optional configuration in the matching installation's encrypted license payload:

```json
{
  "provider_config": {
    "fbr": {
      "environment": "sandbox",
      "api_tokens": {
        "sandbox": "customer-specific-sandbox-token",
        "production": "customer-specific-production-token"
      },
      "seller_ntn_cnic": "0786909",
      "seller_business_name": "Customer legal business name",
      "seller_province": "SINDH",
      "seller_address": "Registered business address",
      "scenario_ids": ["SN001", "SN026"],
      "scenario_id": "SN001"
    }
  }
}
```

- Sandbox and production tokens are distinct and belong to that taxpayer/customer. The client selects only the token matching the centrally selected environment.
- Tokens are stored only inside the encrypted license cache. They are not exposed in store-admin screens, REST responses, logs, email, browser code, or exports.
- The central platform must never return a shared global FBR token.
- Seller NTN/CNIC, legal business name, province and address must match the taxpayer's PRAL/IRIS registration.
- Sandbox scenario IDs and the selected sandbox scenario must be confirmed for that taxpayer's actual business activity. The client never hardcodes SN001 or infers the taxpayer's eligible scenarios.
- Missing/invalid credentials or profile make the integration unavailable; they do not fall back to another customer's token.

## API client implemented

All requests use HTTPS and `Authorization: Bearer <customer token>`. API hosts are fixed in code; customer settings cannot redirect the taxpayer token to an arbitrary URL.

- Sandbox submit: `https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata_sb`
- Production submit: `https://gw.fbr.gov.pk/di_data/v1/di/postinvoicedata`
- Sandbox validate: `https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata_sb`
- Production validate: `https://gw.fbr.gov.pk/di_data/v1/di/validateinvoicedata`

The official specification notes token-based routing, while its environment examples provide the explicit `_sb` sandbox paths above. The client selects the documented sandbox or production path and matching token together.

Reference API methods implemented:
- `/pdi/v1/provinces`
- `/pdi/v1/doctypecode`
- `/pdi/v1/itemdesccode`
- `/pdi/v1/sroitemcode`
- `/pdi/v1/transtypecode`
- `/pdi/v1/uom`
- `/pdi/v1/srosched`
- `/pdi/v2/SaleTypeToRate`
- `/pdi/v2/HS_UOM`
- `/pdi/v2/SROItem`
- `/dist/v1/Get_Reg_Type`
- `/dist/v1/statl`

Reference data is cached for 15 minutes per environment/token/query. The buyer registration lookup accepts 7/9-digit NTN or 13-digit CNIC and returns the PRAL registration type.

## Invoice payload rules

The official DI payload requires seller/buyer identity, province and address, buyer registration type, and per-item values including HS code, product description, rate description, UOM description, quantity, total value including tax, value excluding sales tax, fixed/retail value, sales tax/FED, withholding, sale type, and applicable discount/SRO/further/extra tax values.

- Sandbox requires the centrally selected taxpayer-specific `scenarioId`; production examples omit it.
- HS code, rate, UOM and sale type must come from the PRAL reference APIs and the customer's tax/product mapping. The client must not invent them.
- Buyer registration type can be obtained through `Get_Reg_Type`; buyer NTN/CNIC may be absent only when the buyer is unregistered.
- The FBR response is valid only when the top-level validation status is `00` / `Valid`, every item status is `00` / `Valid`, and the post response contains a non-empty `invoiceNumber`. A top-level status of `00` can still contain an invalid item; item statuses must be inspected.
- HTTP 401 means the customer token is not accepted. HTTP 500, transport timeout, or unreadable response after a post is ambiguous: the invoice may already have been accepted. Such submissions must be marked unknown and must not be automatically posted again.

## Cancellation and correction

The v1.12 technical API documents post and validate methods; it does not document an API cancellation endpoint. PRAL User Manual v1.6 describes cancellation/edit through the IRIS Digital Invoicing portal:
- Corrections within 72 hours of insertion or before month end, whichever occurs first.
- Invoice number remains fixed; header details cannot be changed; item-level corrections are handled in IRIS.
- Invoices already included in submitted returns and invoices linked with Annexure-C are not eligible.
- Cancellation is subject to a 10% of prior-month sales cap.
- After the 72-hour/month-end window, the invoice moves to return and cannot be cancelled.

Junction therefore must not silently cancel/refund an FBR invoice when a WooCommerce order is cancelled or refunded. A human tax operator must reconcile the invoice in IRIS and record the outcome in Junction.

## Remaining taxpayer/business inputs before production submission

The API transport and per-install config reader are implemented. Automatic invoice submission is not enabled yet because the repo still needs confirmed taxpayer-specific data and mapping:
- actual customer sandbox and production tokens, plus seller profile;
- taxpayer business activity, sector, applicable sandbox scenarios and selected scenario;
- product-level HS code, PRAL rate/sale type, UOM, fixed/retail value, and any SRO/FED/extra/further/withholding treatment;
- authoritative tax calculation rules for WooCommerce/POS line values and discount allocation;
- buyer province normalization and policy for missing buyer address/province;
- when the legal supply/invoice event occurs for prepaid, COD, POS, partial refund and cancelled orders;
- required QR-code encoded content. The technical specification mandates a 25x25 (version 2.0) QR code at 1 inch and the FBR Digital Invoicing System logo, but does not define the QR payload in its text.

Until those values are configured and validated against PRAL sandbox scenarios, the integration must remain in setup/needs-review state and must not submit production invoices.
