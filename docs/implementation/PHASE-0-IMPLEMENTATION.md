# Phase 0 implementation — status and operational gates

Branch: `implementation/phase-0-foundation`  
Base: `main` at `38b6eee00d92b5a7240c62fb3e8d06a3f80c3c4b`

This is an implementation branch only. No changes have been merged to `main`, and no production deployment has been performed.

## Implemented in this branch

- Added a PSR-4-compatible exception class file for each license exception. The previous single file declared several classes that the file-based autoloader could not resolve by their individual class names.
- Fixed the license feature guard to use the container property actually declared by `ThemeBootstrapper`.
- Connected the setup wizard to the existing license UI and added a license tab, so license activation/status is reachable from the WordPress admin.
- Made unknown license tiers return no tier-derived entitlements.
- Added a central-entitlement intersection to `FeatureManager`: a local feature toggle can turn a feature off, but cannot grant a feature that the central license does not include.
- Gated payment gateway registration by central entitlement and class availability. EasyPaisa remains unavailable because its gateway class is absent from this repository. Foree is deliberately not registered until its callback is changed to provider-side payment verification; its existing browser-return callback is not safe to authorize payment.
- Validate license API HTTP status and entitlement response shape. Definitively invalid licenses no longer receive the offline grace period. Local deactivation is retained unless the central service confirms deactivation.
- Use authenticated AES-256-GCM for newly stored integration secrets and license cache/key values, while retaining legacy AES-CBC read support for migration. The bundled Groq plugin now encrypts its API key and migrates existing plaintext keys when the assistant next uses them.
- Removed duplicate theme AI chat controller registration. The bundled assistant now checks the central AI entitlement before it initializes.
- Made branding exports omit the client license key. Branding imports accept only allowlisted presentation fields and cannot import feature entitlements or license credentials.
- Restricted POS REST routes to WooCommerce managers and added cashier/session ownership checks for session opening, closing, current session, sale processing, and order-history access. Sale failures no longer expose exception messages to API clients.
- Added a lock-protected, versioned migration runner with required-table verification. Upgrade migrations are held unless a hosting integration confirms a recent full backup through the `pkstore_migration_backup_confirmed` filter.
- Added a GitHub Actions workflow for PHP lint, dependency-free smoke tests, and JavaScript syntax checks.

## Additional source QA fixes — 21 November 2026

A second source review found and corrected these issues before considering the branch ready:

- Offline license grace now re-checks the cached license expiry and bound domain. Definitive invalid, revoked, expired, and domain-mismatch responses clear stale cached entitlements so later feature checks in the same PHP request cannot reuse them.
- HTTP 401 and 410 license responses are treated as definitive invalid/revoked states rather than service outages eligible for grace.
- Existing, unversioned installations are now detected by their existing PK Store tables. They cannot be mistaken for fresh installs to bypass the pre-migration backup confirmation gate.
- Legacy plaintext integration secrets are migrated to authenticated AES-256-GCM storage on read, and the plaintext option is deleted only after the encrypted value is saved. A present but unauthentic encrypted value fails closed rather than falling back to plaintext.
- Package smoke coverage now checks Basic, Standard, and Enterprise entitlements and prevents higher-tier extras leaking into lower tiers.

## Validation status

- Source changes are committed on this branch.
- GitHub Actions workflow has been added and runs are being created, but each job currently fails before executing any step (GitHub reports runner ID 0, no runner name, and an empty step list). Therefore PHP lint and smoke tests are **not yet confirmed passing**; repository Actions/runner availability must be fixed or a runner made available.
- No WordPress/WooCommerce runtime test was possible from the repository connector.
- No production credentials, payment-provider credentials, or hosting account credentials were accessed.
- No merge or deployment was performed.

## External prerequisites still required before Phase 0 can be declared production-ready

### Central licensing service

The licensing API implementation is not present in this repository. The client currently relies on the external `license.junctionsystems.pk` service. The API's response-signing contract, package entitlement payload, payment lifecycle and downgrade propagation must be verified against that service. The client validates HTTPS response status and schema, but cannot implement response HMAC verification without the server's documented signing contract and matching server-side change.

### Hosting backups and restore

Junction owns automated hosting backups; target RPO is 24 hours. Customer self-service export remains optional and is not a substitute for Junction's backup responsibility. No hosting account connection or provider backup/restore API was available in this implementation session, so backup schedules, retention, restore tests, and actual RPO cannot be certified here.

The migration runner intentionally blocks future upgrades until the hosting layer supplies a truthful `pkstore_migration_backup_confirmed` filter result. That integration must only return true after a recent full database and website-files backup has completed and is verifiable.

### Payment providers

- JazzCash callbacks now verify the signed transaction reference, expected amount, merchant, currency and success code against the initiated order.
- EasyPaisa cannot be activated from this repository because the referenced gateway class is missing.
- Foree is intentionally withheld from gateway registration pending the provider's documented server-to-server verification endpoint and response-signature contract. A browser query-string `SUCCESS` is not proof of payment.

### Runtime and recovery acceptance

Before production rollout, run the workflow successfully, install this branch on a staging WordPress + WooCommerce site, exercise license activation/expiry/offline/revocation, test JazzCash sandbox callbacks and replay cases, test POS cashier isolation, verify secret migration from legacy options, and perform a full restore from the hosting backup. Record the measured RPO and RTO. Do not deploy until these checks pass.

## Explicitly out of scope for this Phase 0 branch

The later POS/inventory transaction redesign, FBR integration, central billing portal, Groq multi-key pool, courier remittance reconciliation, full support ticketing/human handoff, and marketing/ERP integrations remain in their own implementation phases. This branch does not claim those features are complete.

## Additional source QA — 21 November 2026 (continued)

- Corrected the bundled Groq assistant's legacy-key migration path: it now confirms the encrypted option was persisted before use, decrypts only the stored ciphertext for the outbound request, and refreshes the settings array after migration so the same request does not reuse a stale plaintext option.
- The latest workflow run for commit `247b85960d315d43d96af969a237f48c1b885476` (run `44866110191`) still terminates in approximately two seconds with `runner_id: 0`, no runner name, and an empty `steps` array. This confirms the job never reached checkout or PHP/Node execution. The repository connector cannot inspect or change the account's Actions/billing/runner policy settings, so the exact account-side cause remains unverified.
- No code-level test result is being represented as passing. The workflow must execute on a runner before PHP lint, smoke tests, and JavaScript syntax checks can be accepted.
