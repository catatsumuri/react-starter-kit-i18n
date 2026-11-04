---
paths:
  - 'lang/**'
---

# Lang

## Treat language files as generated output
Do not edit `lang/{locale}.json` or `lang/{locale}/**` directly. Keep project-specific English source strings and locale translations in `lang/vendor-patches/**`, then run `composer patch-vendor` followed by `php artisan lang:update`.
