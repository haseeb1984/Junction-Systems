# Junction Systems — Feature & Gap Audit Re

**Owner decision recorded (16 Nov 2026):**

- The AI must not cancel WooCommerce orders itself. Cancellation requests must be handed to human support for review and processing, to prevent abuse or customers misusing automated cancellation.

**Proposed behaviour awaiting owner verification:**
- Look up an order using an order number, then verify customer ownership before disclosing order-specific information.
- Provide order status and payment status from WooCommerce.
- Provide courier name, tracking number and current shipment status when available from the connected courier integration.
- Provide delivery estimates only when supported by verified courier data; do not invent dates or shipment events.
- Do not expose personal details such as address or phone number without appropriate verification.
- Include a direct courier tracking link when the integration supplies a valid URL; otherwise explain that a direct link is unavailable.

## Status

- Initial source inventory and first AI/product-context review completed.
- Owner confirmed full-catalogue product discovery, exact variation availability/price checking, product answers containing SKU, current sale price, simple In Stock/Out of Stock status and a direct product link, catalogue-grounded popular recommendations with no invented product details, and no customer-facing exact stock quantities; recorded above.
- Full feature-by-feature source review: pending.
- Runtime/integration/security tests: pending.
- Owner feature verification: pending.
- No production changes or merges performed as part of this audit.
