---
paths:
    - 'app/**'
    - 'routes/**'
    - 'config/**'
    - 'tests/**'
---

# Security Audit

## Preserve security audit coverage

When adding or changing authentication, credentials, MFA or passkeys, sessions, account state, authorization, privileged operations, or sensitive-data exports, evaluate whether the operation needs a security audit record.

For auditable operations, dispatch an event only after the operation succeeds and record it through the existing `RecordSecurityActivity` path. Keep actor and target distinct, use minimal allowlisted metadata, and add regression coverage. Never record passwords, secrets, tokens, cookies, complete session IDs, recovery codes, WebAuthn assertions, or request bodies.

Do not persist attacker-controlled, high-volume failures to the primary audit database; use the existing security failure log path.

New audit events must not appear in user-facing security activity unless they are explicitly added to its allowlist.
