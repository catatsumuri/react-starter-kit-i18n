---
paths:
  - '**/*'
---

# General

## Use project scripts for verification
Read composer.json scripts and package.json scripts before choosing verification commands; keep command definitions there and prefer the existing scripts over invoking their underlying tools directly. During implementation, run the narrowest affected tests; for PHP-wide verification use composer test, for frontend-and-backend verification use composer ci:check, and for PHP static analysis alone use composer types:check. Match verification scope to the change and retain the required vendor/bin/pint --dirty --format agent step for PHP edits. If an aggregate command stops early, report the failure and any checks that did not run; do not claim that the full pipeline passed.
