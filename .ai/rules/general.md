---
paths:
  - '**/*'
---

# General

## Keep authentication identifiers canonical
When working with email or username identifiers, normalize them with trim + lowercase for validation, lookup, rate-limit keys, and persistence. Usernames are required and unique, use 3–32 characters from `a-z0-9._-`, and keep database, backend, and form constraints aligned.
