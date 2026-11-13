# Juncsys / Junction Systems — Gaps and Verification Register

> Items below are source-review findings and verification tasks, not a statement that the owner-tested product is broken.

## POS and inventory consistency
1. Review the inspected POS permission callback: it accepted `manage_woocommerce` OR `edit_posts`; confirm and narrow financial-operation access to the intended dedicated capabilities/roles.
2. The inspected sale path appeared able to continue when stock deduction fails. Confirm the intended overselling/reservation policy and enforce a consistent transaction outcome.
3. Verify sale totals, split-payment arithmetic, receipt/order history and offline queue replay under duplicate, delayed and interrupted requests.
4. Verify inventory invariants across locations, purchase orders, stock adjustments, POS sales and WooCommerce synchronization.

## Dukaan AI
- Verify each provider/model combination; one inspected service path appeared to pass a Gemini model identifier through an OpenAI provider path.
- Confirm the active path, supported model names, fallback behavior, streaming errors and credential configuration.
- Maintain prompt-injection and product-context boundaries with regression tests.

## Integrations and operations
- Verify payment and courier callbacks for signature validation, idempotency, retries and reconciliation.
- Confirm production merchant/provider credentials separately from source-level integration support.
- Document Mailchimp/Klaviyo authorization scopes, sync behavior and failure handling.
- Confirm installation, configuration, backup/restore and rollback procedures for the WordPress/WooCommerce environment.

## Quality and release management
- No dedicated automated test files were found in the inspected tree; verify whether tests exist elsewhere and add coverage for POS, inventory, checkout, AI and integrations.
- Theme metadata version and internal `PKSTORE_VERSION` appeared inconsistent; confirm the intended release-version source.
- Record supported WordPress, WooCommerce and PHP versions.

## Evidence discipline
Treat these as findings to validate. Do not downgrade working features or claim a defect is confirmed without reproducing it and checking the intended business rule.
