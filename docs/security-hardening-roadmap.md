# Security Hardening Roadmap

Status: proposal / not implemented
Scope: `catatsumuri/react-starter-kit-i18n`, branch `feature/login-with-email-or-username`
Updated: 2026-10-10

## Purpose and current assessment

The starter kit already offers Laravel Fortify password authentication, email-or-username login, optional TOTP 2FA/recovery codes, and passkey support. It also has a rate limiter for login, 2FA, and passkeys. This is a capable authentication *entry point*, but it does not establish security observability, user-facing session control, incident response, or a system-wide security operations workflow.

Do not infer from a stolen session that passwords or MFA were broken. A stolen bearer session cookie may be replayed without a fresh Login/2FA/passkey event. Likewise a copied cookie can appear as the **same session**, rather than as a new device. Session listing and audit logging are useful but cannot reliably identify every hijack.

Verified repository anchors (as of the specified branch):
- `config/fortify.php`: optional 2FA and passkeys, rate limiters; `username` config remains `email` while the handler supports both identifiers.
- `app/Providers/FortifyServiceProvider.php`: custom `authenticateUsing`, per-login-string+IP login limiter.
- `config/session.php`: defaults to `database` driver with 120-minute idle lifetime, but runtime env may override.
- `database/migrations/0001_01_01_000000_create_users_table.php`: `sessions` table has `id`, `user_id`, `ip_address`, `user_agent`, `payload`, `last_activity`.
- `routes/settings.php`: no dedicated session-list/revoke routes.
- `tests/Feature/Auth/AuthenticationTest.php`: tests for email/username login, rate limiting, and 2FA challenge redirect.

## Principles

1. **No new super-admin by default.** Prefer operator CLI and external alerting, then add narrowly scoped RBAC/admin UI only with a demonstrated need.
2. **Separate evidence, detection, and response.** Audit records explain what happened; counters detect anomalous activity; revocation/step-up constrain impact.
3. **Don't store secrets in audit logs.** Never log passwords, recovery codes, TOTP secrets, full session IDs, session cookies, reset tokens, WebAuthn assertions, or sensitive request bodies.
4. **Avoid user enumeration and lockout-as-DoS.** An attacker can intentionally trigger failures for a victim.
5. **Secure by default, configurable by deployment.** Do not assume production uses the database session driver.
6. **Each phase must have automated tests and documentation.** No silent changes to authentication behavior.
7. **Logs within the compromised application DB can be altered.** External durable logging is a separate defense layer.

## Phase 0 — Inventory, threat model, tests (first)

- [ ] Document Fortify password, username/email, passkey, TOTP, recovery-code, password-reset, verification, and session lifecycles.
- [ ] Verify exactly which framework events fire on password success/failure, lockout, TOTP success/failure, passkey success/failure, and session revocation. Do not assume each path emits `Login`.
- [ ] Verify supported session drivers in production and tests; explicitly scope initial session UI to `database` unless an adapter is built.
- [ ] Identify whether applications have privileged operations or bulk exports that require separate auditing.
- [ ] Record current baseline tests and determine production log retention/access requirements.

**Exit criteria:** executable integration-test matrix for every auth path and a documented session driver support policy.

## Phase 1 — User-controlled sessions (first user-facing feature)

Goal: give users a way to review and revoke sessions even before advanced anomaly detection exists.

- [ ] Add a settings screen for current and other authenticated sessions, populated only from the signed-in user's records.
- [ ] Show browser/device description derived from User-Agent, approximate IP, last activity timestamp, and a clearly marked *current session*. Labels like "device" are estimates, not trusted device identity.
- [ ] Implement revoke-one-other-session and revoke-all-other-sessions; consider revoke-all-including-current as a distinct confirmed action.
- [ ] Authorization must restrict every delete to `user_id = auth()->id()`; do not accept or expose raw reusable session secrets in UI or logs. Prefer opaque UI identifiers or carefully scoped signed actions.
- [ ] Require fresh authentication for destructive session controls; account for passkey-only accounts (do not make password confirmation the sole possible route).
- [ ] Deleting a DB session should prevent future requests using it; test that explicitly. Do not imply immediate interruption of already-running requests.
- [ ] Handle expired/stale session rows, pagination/limits, inaccessible storage, and non-database drivers safely.
- [ ] Document that a stolen *copy* of an existing cookie may share the same listed session; revoking that session logs out both holders.

**Exit criteria:** owner-only session list; effective individual and bulk revocation; proper self-session behavior; feature tests for IDOR, current vs other session, hijacked-copy scenario, expiry, and rate limiting of revoke endpoints.

## Phase 2 — Audit events and reviewable history

Use Laravel Events/listeners as the event boundary. Evaluate **Spatie laravel-activitylog** as the leading option for persisted application audit events and selected Eloquent change histories, but don't couple security semantics to the package. Compare it with a small custom event store or a dedicated Monolog channel before deciding.

- [ ] Define a stable schema: event name, outcome, occurred_at, actor_user_id (nullable), target_user_id (nullable), correlation/request ID, session fingerprint/reference (non-reusable), client IP, sanitized UA, auth method, and limited safe metadata.
- [ ] Record login success/failure, throttling/lockout, 2FA/recovery usage, passkey registration/removal, password/email changes, session revocation, privilege changes, and bulk exports *when the application supports them*.
- [ ] Confirm actual event emission and avoid treating a password check followed by a 2FA challenge as a completed login.
- [ ] Avoid hashing low-entropy usernames as a purported privacy fix; choose retention, minimization, restricted visibility, and keyed/HMAC identifiers if needed.
- [ ] Provide a minimal user-facing view of recent successful authentication and security-critical changes. Displaying *all failed attempts* is optional and may confuse users.
- [ ] Create retention/pruning policy and authorization rules for access to audit data.
- [ ] Do not write every high-volume unauthenticated failure synchronously to the primary DB during an attack; evaluate sampling/aggregation/external logging.

**Exit criteria:** end-to-end audit coverage tests without leaked credentials or excessive write amplification; records can answer "who/when/how" for supported operations.

## Phase 3 — Detection and notification

- [ ] Aggregate short-window events (e.g. Redis), including per IP, per account, and across accounts; normalize username/email variants to a canonical account where safely possible.
- [ ] Alert operators via deployment-configured external channels (e.g. email/Slack/CloudWatch) on unusual spikes, distributed attempts, or suspicious security-setting changes.
- [ ] Notify account owners of consequential events such as passkey/2FA removal, password change, session revocation, and risk-significant new authentication.
- [ ] Add rate limits/deduplication to notifications; do not put full IP/session identifiers in external messages unnecessarily.
- [ ] Treat changes in IP, geolocation, or User-Agent as weak signals, not proof or automatic global lockout.
- [ ] Test failure of Redis, email, and external notification services without blocking normal login unnecessarily.

**Exit criteria:** reproducible synthetic alert scenarios, bounded DB writes, no alert flood, documented operator runbook.

## Phase 4 — Containment and high-risk operations

- [ ] Provide a CLI/runbook to revoke sessions by user or cohort, temporarily disable an account if required, and inspect recent audit entries—without first creating a web super-admin.
- [ ] Require fresh user verification for high-impact changes and exports. Prefer strong step-up methods supported by account enrollment; re-auth must be server enforced, fresh, and action-scoped.
- [ ] Consider idle and absolute session lifetimes and revocation on critical credential/security changes; document UX costs.
- [ ] Evaluate logging/limits for bulk reads and exports at application level. Auth audit alone cannot prove what records were exfiltrated.
- [ ] Provide response instructions for an infostealer infection: clean/rebuild device first, then revoke sessions and rotate exposed credentials and keys.

**Exit criteria:** tested incident drill from suspected takeover to containment; proof that sensitive actions remain constrained under a stolen-but-valid cookie where feasible.

## Phase 5 — Optional operator interface and durable logs

- [ ] Add an operator UI only if real operational needs justify it; adopt least-privilege roles, separation of duties, step-up auth, and complete audit of operator actions.
- [ ] Export audit/security events to an independently controlled service with restricted write/delete permissions (e.g. CloudWatch Logs plus suitable retention/access controls).
- [ ] Define observable metrics, alert ownership, escalation, data retention, cost budget, and privacy disclosures.
- [ ] Periodically test session revocation and log export under simulated attack conditions.

## Package decision: Spatie Activitylog

**Provisional decision:** suitable as an in-app persistent activity/audit log, particularly if the app wants Eloquent change tracking in addition to authentication events. **Not** a session manager, intrusion detector, immutable security log, or full incident-response product. Don't adopt it just to store massive unauthenticated failure streams. Re-evaluate after the Phase 0 event-volume and retention inventory.

## Non-goals and hazards

- No claim that MFA/passkeys prevent replay of an already stolen session cookie.
- No claim that session listings reveal every stolen/copied cookie.
- No device fingerprint presented as definitive identity.
- No automatic universal account lock after N failures (can be weaponized for denial of service).
- No silent requirement that every account have a password, since passkey-only support may exist in future.
- No blanket logging of model attributes, session payloads, personal information, or secret material.
- No requirement that an admin dashboard ship before audit, self-service revocation, and response tooling.

## Handoff instructions for a development agent

1. Inspect the current branch; do **not** assume paths/features are unchanged.
2. Start with Phase 0 and Phase 1; propose a minimal diff before implementing later phases.
3. Prefer framework-native auth/session APIs and test them; use raw DB access only where necessary and after driver checks.
4. Add focused Pest/PHPUnit feature tests for privilege boundaries and actual invalidation behavior.
5. Keep each phase in a separate reviewable PR/commit; do not combine admin roles, risk scoring, and session management in one patch.
6. Report unresolved assumptions explicitly, especially passkey-only step-up and session store portability.
