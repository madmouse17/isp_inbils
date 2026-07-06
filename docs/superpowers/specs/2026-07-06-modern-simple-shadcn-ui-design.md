# Modern Simple shadcn-style UI Design

## Goal

Redesign the admin UI to feel modern, simple, and consistent using shadcn/ui conventions while preserving current Laravel, Inertia, React, Tailwind, routes, permissions, forms, filters, and pagination behavior.

## Scope

In scope:

- Add shadcn/ui project configuration and theme conventions.
- Convert the existing shared UI layer under `resources/js/Components/ui` to shadcn-style primitives.
- Refresh the admin shell: sidebar, topbar, page background, spacing, responsive navigation, and dark mode.
- Rewrite admin pages onto the refreshed shared primitives.
- Keep Indonesian labels where pages already use Indonesian copy.
- Verify key admin flows in browser plus TypeScript/build checks.

Out of scope:

- Backend/API changes.
- Route or permission changes.
- New product features.
- Full visual rebrand beyond the neutral shadcn-style theme.
- Adding new UI libraries unless a component requires them.

## Constraints

- Keep Inertia + React + Tailwind.
- Keep `@/Components/ui` as the primary UI import path.
- Reuse existing `cn()` helper in `resources/js/lib/utils.ts`.
- Prefer existing installed dependencies first. Add dependencies only when needed by a shadcn-style component that cannot be safely covered by existing code.
- Avoid one large risky rewrite. Ship the full redesign through staged commits.

## Visual System

Use shadcn-style CSS variable tokens:

- `background`, `foreground`
- `card`, `card-foreground`
- `popover`, `popover-foreground`
- `primary`, `primary-foreground`
- `secondary`, `secondary-foreground`
- `muted`, `muted-foreground`
- `accent`, `accent-foreground`
- `destructive`, `destructive-foreground`
- `border`, `input`, `ring`

Style rules:

- Neutral palette first; brand color used only for primary actions and active navigation.
- `rounded-lg` as default radius.
- Thin borders; soft shadows only on overlays and elevated cards.
- Dense admin spacing: `p-4 md:p-6`, `gap-4`, compact table rows.
- Clear focus rings for keyboard users.
- Dark mode remains class-based and controlled by the existing toggle.

## Architecture

The redesign keeps the current application architecture and changes the UI layer.

1. Add `components.json` at the project root for shadcn/ui conventions.
2. Extend `resources/css/app.css` with shadcn-style CSS variables and base styles.
3. Extend Tailwind config with variable-backed colors and radius tokens while keeping existing `brand` and `surface` tokens during migration.
4. Update shared UI primitives in `resources/js/Components/ui` to shadcn-style classes and accessible states.
5. Update `AdminLayout`, `Sidebar`, and `Topbar` to provide the new shell.
6. Rewrite admin pages to use the refreshed primitives consistently.

The existing `@/Components/ui` API stays where practical so pages can migrate incrementally without import churn.

## Shared Components

Primary components to convert first:

- `Button`, `IconButton`
- `Input`, `Textarea`, `Select`, `Checkbox`, `Switch`, `RadioGroup`, `Label`
- `Card`, `StatCard`
- `Table`, `DataTable`, `Pagination`
- `Badge`, `Avatar`
- `Alert`, `EmptyState`, `Toast`, `Skeleton`, `Spinner`
- `Breadcrumb`, `Tabs`
- `Modal`, `Dropdown`, `Tooltip`
- `Sidebar`, `Topbar`

Component behavior requirements:

- Preserve current props where possible.
- Preserve loading and disabled states.
- Preserve accessible labels, error text, `aria-invalid`, modal close, and focus states.
- Avoid adding Radix dependencies unless current Headless UI/native implementation cannot meet behavior cleanly.

## Admin Shell

Admin shell layout:

- Desktop: fixed-width sidebar, sticky topbar, scrollable main content.
- Mobile: sidebar drawer with backdrop and accessible close behavior.
- Topbar: title, mobile menu button, company link, dark-mode toggle.
- Sidebar: grouped navigation, active state, compact labels, clear hover/focus states.
- Main content: neutral background with centered max-width only where pages need it; data-heavy pages can use full available width.

## Page Pattern

Each admin page should follow this pattern:

```tsx
<AdminLayout title="Page title">
    <PageHeader
        title="Page title"
        subtitle="Short purpose text"
        breadcrumbs={[...]}
        actions={...}
    />
    <Card>...</Card>
</AdminLayout>
```

Use:

- `PageHeader` for title, subtitle, breadcrumbs, and actions.
- `Card` for filters, forms, sections, and grouped content.
- `DataTable` or `Table` for lists.
- `Modal` for destructive actions and focused workflows.
- `EmptyState` for empty data.
- Existing Inertia form handling for create/edit pages.

## Rewrite Order

1. Foundations: `app.css`, `tailwind.config.js`, `components.json`, shared UI primitives.
2. Shell: `AdminLayout`, `Sidebar`, `Topbar`.
3. High-traffic list pages: dashboard, customers, subscriptions, billing invoices, billing receivables, tickets.
4. Forms and detail pages: create/edit/show flows for high-traffic modules.
5. Remaining admin modules: reports, inventory, network assets, service, HR, documents, organization, settings.
6. Component gallery last, documenting final primitives.

## Error Handling and Accessibility

- Keep existing Inertia form errors.
- Inputs show visible error text and `aria-invalid`.
- Buttons expose loading and disabled states.
- Modal dialogs keep keyboard focus and close affordances.
- Destructive actions keep confirmation.
- Empty tables show useful empty states.
- Color contrast must work in light and dark themes.

## Verification

Required checks:

- `rtk npm run typecheck`
- `rtk npm run build`
- Browser smoke check:
  - admin dashboard
  - customers index
  - invoices index
  - tickets index
  - one create/edit form
- Run PHP tests only if UI changes touch route names, form payload shape, or backend behavior.

## Delivery Plan

Use staged commits to reduce regression risk:

1. Theme/config commit.
2. Shared primitive conversion commit.
3. Admin shell commit.
4. High-traffic pages commit.
5. Remaining pages commit.
6. Final gallery/docs/verification commit.

Each commit should pass TypeScript/build before moving to the next stage when practical.
