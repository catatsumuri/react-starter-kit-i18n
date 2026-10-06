---
paths:
  - 'resources/js/**/*.tsx'
---

# Js

## Use shadcn/ui for dialogs

Reuse the existing shadcn/ui Dialog components for application dialogs. Do not use window.alert(), window.confirm(), or window.prompt(). Check existing dialogs and shared components before creating a new implementation.
For confirmation dialogs, execute the action only after explicit confirmation. Canceling or closing the dialog must not execute the action. Use the existing translation mechanism for displayed text.

## Add UI translations through vendor patches
When adding or changing translated UI text, follow the translation rules indexed for lang/**. Manage custom English keys in lang/vendor-patches/source/react.json and locale translations in lang/vendor-patches/locales/{locale}.json; never add them directly to generated lang/{locale}.json. Run composer patch-vendor followed by php artisan lang:update --no-interaction, and use the existing lang() / __() helpers in the UI.
