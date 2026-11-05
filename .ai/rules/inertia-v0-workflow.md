---
paths:
    - 'index.html'
    - 'resources/js/pages/welcome.tsx'
    - 'mock/**'
    - 'vite.preview.config.ts'
    - 'vite.config.ts'
    - 'routes/web.php'
---

# v0 + Laravel/Inertia preview workflow

## Non-negotiable: one React UI source

- The **real Laravel/Inertia page** under `resources/js/pages/welcome.tsx` is the only source of UI markup for the landing page.
- The PHP-free v0 preview MUST import and render that exact component. Never create or reintroduce `mock/welcome.tsx`, duplicated page JSX, or a separate Next.js page.
- v0 is used as a visual design/editor workflow for the existing Inertia React component, **not** to migrate the app to Next.js.

## Preview responsibilities and supported pages

- Root `index.html` is the PHP-free preview entry point and loads `mock/main.tsx`. Laravel uses its Blade/Inertia entry point (`resources/views/app.blade.php`); it does not consume this HTML file.
- `mock/main.tsx` explicitly selects a page by URL. Laravel routes (`routes/web.php`) and the preview's URL matching are **not** automatically synchronized. Currently only `/` is supported; other URLs render the preview 404. Login, registration, and dashboard previews are not implemented yet (they need a non-null `usePage()` user and the shared props their layout reads — see noise-form's `.ai/rules/inertia-v0-workflow.md` for the pattern used to add that).
- `mock/routes.ts` supplies replacements for the imported Wayfinder route helpers (`dashboard`, `login`, `register`). Returning a URL from a helper does not make that page available in the preview; keep URL helpers and page resolution conceptually separate.
- `mock/lang.ts` returns translation keys unchanged. English-key rendering is sufficient for this design preview; do not recreate the Laravel translation pipeline here.
- `mock/inertia.tsx` implements only the Inertia API surface needed by supported preview pages (`Link` as a normal anchor, `Form` blocking persistence with a preview message, `Head` updating the document title, `usePage()` returning fixed guest props). Extend it only for the APIs a newly previewed page actually uses.
- Add a page to the preview only when it is needed for design work. Import the real page, add URL resolution and representative typed props if it takes any, and extend adapters only for what that page and its layout actually use.
- Unsupported URLs must remain explicit 404s. Do not silently render the home page or imply that an unimplemented login, save, or logout action succeeded.

## Build and branch boundaries

- `v0/design` is the design/preview branch; `main` is the Laravel integration branch. Commit design/preview changes on `v0/design`, verify them, then merge `v0/design` into `main`.
- `npm run dev:preview` and `npm run build:preview` use `vite.preview.config.ts` for a PHP-free v0 preview. `npm run dev` and `npm run build` use the default `vite.config.ts` (Laravel-oriented, unchanged by this mechanism).
- Both branches retain `mock/`, root `index.html`, and `vite.preview.config.ts` for the shared-page preview.
- If v0 creates a new branch, compare and intentionally merge its UI changes into `v0/design`; never overwrite another branch's unrelated work.

## Checks before declaring success

1. Verify `mock/main.tsx` imports `../resources/js/pages/welcome` and that `mock/welcome.tsx` does **not** exist.
2. Check the preview build (`npm run build:preview`) and the Laravel build (`npm run build`) separately.
3. Distinguish **Git commit/push success** from **runtime preview success**; do not claim an untested preview works.
4. Existing project-wide rules (including `.ai/rules/js.md`) still apply; this file adds workflow constraints and does not override them.
