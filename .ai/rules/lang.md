---
paths:
  - 'lang/**'
---

# Lang

## Manage translations through vendor patches
Treat lang/{locale}.json and lang/{locale}/*.php as generated files; do not edit them directly. Their source of truth is the upstream Laravel Lang packages (including laravel-lang/starter-kits) plus lang/vendor-patches. Add custom English keys to lang/vendor-patches/source/react.json and translations or upstream overrides to lang/vendor-patches/locales/{locale}.json. After editing patches, run composer patch-vendor, then php artisan lang:update --no-interaction to regenerate the application language files. Do not edit vendor files manually.
