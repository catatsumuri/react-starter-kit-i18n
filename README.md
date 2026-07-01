# Laravel + React Starter Kit — i18n Edition

A fork of the official [Laravel React starter kit](https://laravel.com/docs/starter-kits) with internationalization (i18n) built in.

## What's different from the official kit

- **Japanese pre-configured** — `ja` locale ships ready to use alongside `en`
- **Translation pipeline** — `lang/vendor-patches/` controls which locales are generated; add a JSON file, run `lang:update`, done
- **Translations in React** — `__()` helper available in all React components via Inertia shared props
- **Session-based locale persistence** — active locale survives page reloads via `LocalizationBySession` middleware

## Requirements

- PHP 8.2+
- Node 20+
- A database supported by Laravel (SQLite works for local dev)

## Installation

```bash
composer create-project your-vendor/react-starter-kit-i18n my-app
cd my-app
composer setup
```

`composer setup` does the following in one shot:

```
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm install
npm run build
```

## Development

```bash
composer dev
```

Starts four processes concurrently:

| Process | Command |
|---------|---------|
| HTTP server | `php artisan serve` |
| Queue worker | `php artisan queue:listen` |
| Log viewer | `php artisan pail` |
| Vite dev server | `npm run dev` |

## Adding a locale

1. Create an empty patch file:
   ```bash
   echo '{}' > lang/vendor-patches/locales/ko.json
   ```
2. Regenerate translation files:
   ```bash
   php artisan lang:update
   ```

That's it. The new locale is now available to the application.

The `lang/vendor-patches/locales/` directory is the install trigger — `lang:update` scans `lang/` recursively for JSON files to determine which locales to generate. 

## Architecture

### Backend packages

| Package | Role |
|---------|------|
| `laravel-lang/common` | Core translation pipeline (`lang:update` command) |
| `laravel-lang/routes` | `LocalizationBySession` middleware |
| `erag/laravel-lang-sync-inertia` | Syncs `lang/{locale}.json` to Inertia shared props as `lang` |
| `laravel/wayfinder` | Type-safe route references on the frontend |

### Frontend

- React 19 + TypeScript + Vite
- [shadcn/ui](https://ui.shadcn.com) + [Radix UI](https://www.radix-ui.com) components
- Tailwind CSS
- `@erag/lang-sync-inertia` — `lang()` / `__()` hook for translations in React components

### How translations reach the frontend

```
lang/ja.json
    ↓  HandleInertiaRequests::share()
Inertia shared prop: { lang: { "Log in": "ログイン", ... } }
    ↓  @erag/lang-sync-inertia
const { __ } = lang()
    ↓
__('Log in')  →  "ログイン"
```

## Code quality

```bash
composer lint          # PHP (Pint)
npm run lint           # JS/TS (ESLint)
npm run types:check    # TypeScript
composer test          # PHPUnit / Pest
composer ci:check      # all of the above
```

## License

MIT
