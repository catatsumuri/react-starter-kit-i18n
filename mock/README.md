# v0 design preview

v0 and Laravel render **the same TSX page** from `resources/js/pages/`.
The preview only replaces the navigation/translation/route-helper mechanism.

| URL | Shared page                      | Notes                        |
| --- | --------------------------------- | ----------------------------- |
| `/` | `resources/js/pages/welcome.tsx` | Takes no Inertia props today |

`mock/main.tsx` matches the URL and renders the real page for the PHP-free
preview. `mock/inertia.tsx` maps Inertia's `Link`/`Form`/`Head`/`usePage` to
preview-only substitutes. `mock/lang.ts` and `mock/routes.ts` stand in for
`@erag/lang-sync-inertia/react` and the generated Wayfinder `@/routes`
helpers.

## Commands

- v0 PHP-free preview: `npm run dev:preview`
- Preview build: `npm run build:preview`
- Laravel + Inertia: `npm run dev`
- Laravel build: `npm run build`

Use `vite.preview.config.ts` for v0 and `vite.config.ts` for Laravel.
Do not introduce a second `mock/welcome.tsx`: edit the shared page directly.

## Adding another page to the preview

Laravel routes and the preview's URL matching are **not** auto-synced.
To preview another page: add a URL check in `mock/main.tsx`, import the real
page component, supply representative fixture props if it takes any, and
extend `mock/inertia.tsx` only for the Inertia APIs that page and its layout
actually use (e.g. authenticated pages need a non-null `usePage()` user and
the shared props their layout reads).
