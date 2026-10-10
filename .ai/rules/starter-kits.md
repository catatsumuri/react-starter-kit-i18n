---
paths:
  - 'vendor/laravel-lang/starter-kits/**'
---

# Starter Kits

## Apply starter-kit translations through managed patches
Do not manually edit the Laravel Lang starter-kit vendor files. Maintain additions and overrides in `lang/vendor-patches/**`; `composer patch-vendor` may deterministically apply those managed patches before `php artisan lang:update`.
