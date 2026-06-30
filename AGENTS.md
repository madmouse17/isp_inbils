# inbils — Project Agent Guide

Stack: Laravel 12 + Inertia.js + React 18 + TypeScript + Tailwind CSS v3 + Vite.
Auth: Laravel Breeze (React/Inertia). DB: MySQL (Laragon).

## UI Component Convention (MANDATORY)

All UI MUST be built from reusable components under `resources/js/Components/ui/`.
This is the single source of truth for the design system.

- Pages (`resources/js/Pages/**`) compose `Components/ui/*` — they NEVER inline
  raw `<button>`, `<input>`, `<table>`, or hand-rolled styled elements.
- If a primitive is missing from `Components/ui/`, ADD it there first, then use it.
- Never duplicate styling logic across pages. Variants belong on the component
  (via a `variant` prop or `cva`), not as page-level `className` overrides.
- Breeze-shipped components (`Components/*` outside `ui/`, e.g. `PrimaryButton`,
  `TextInput`, `InputLabel`) are legacy auth scaffolding. Do not extend them
  for new dashboard UI — use `Components/ui/*` equivalents instead.

## Design System

- Theme: modern flat. No heavy gradients, no faux-3D, no drop shadows deeper
  than `shadow-sm`. Rounded corners (`rounded-lg`/`rounded-xl`), generous
  whitespace, restrained palette.
- Dark mode: every component MUST support `dark:` variants. The app toggles
  via the `class` strategy (set on `<html>`).
- Color tokens: see `tailwind.config.js` → `theme.extend.colors.brand`. Use
  semantic names (`brand`, `surface`, `muted`, `danger`, `warning`, `success`),
  never raw hex in components.
- Typography: Figtree (already wired). Headings via `font-semibold`/`font-bold`.
- Focus states: always visible `focus:ring` for keyboard a11y.
- Interactive elements: clear `hover:` + `active:` + `disabled:` states.

## Code Standards

- TypeScript everywhere. Props typed with explicit interfaces (no `any`).
- React components: function components, named exports, `.tsx` extension.
- One component per file. File name = PascalCase = component name.
- Props: spread forwarded refs where relevant (`React.forwardRef` for
  inputs/buttons so they integrate with forms).
- Keep components presentational; fetch/data logic stays in Inertia props or
  Laravel controllers. No `fetch`/`axios` calls inside `Components/ui/*`.
- Accessibility: semantic HTML, `aria-*` where needed, labels for inputs,
  `role` for non-native widgets (modals, dropdowns).

## Layout & Folder Structure

```
resources/js/
  Components/
    ui/            # design system primitives (Button, Input, Card, ...)
      index.ts     # barrel export
  Layouts/
    AdminLayout.tsx   # sidebar + topbar shell for dashboard
  Pages/
    Admin/         # dashboard pages compose Components/ui/*
```

## Dev Commands

- Backend: `php artisan serve` (port 8000)
- Frontend HMR: `npm run dev`
- Build: `npm run build`
- Migrate: `php artisan migrate:fresh --seed`
- Tests: `php artisan test`

## Login (seeded)

- admin@inbils.test / password
