# Security & RBAC Policy — inbils

## 1. Principles
- Least privilege per persona.
- Separation of Duties (SoD): high-risk execution and approval/reversal/cancellation are mutually exclusive for non-admin personas.
- Multi-tenant company isolation enforced via `BelongsToCompany` and foreign key constraints.
- Backend decides first: every action is authorized by a Form Request / Policy; hidden buttons are never the only control.

## 2. RBAC Matrix & Capability Bundles

### 2.1 Seeded Personas
- **admin**: Full system administrator (break-glass role, holds all capabilities).
- **manager**: Operational controller (approver, reviewer, auditor, reversal authority).
- **staff**: Operational operator (intake, creation, execution, dispatch, collection).
- **technician**: Field technician (execution of assigned work, evidence submission).
- **customer**: External portal user (ticketing and evaluation submission only).

Seeded roles are global (not per tenant). `RolePermissionSeeder` uses `syncPermissions` on the five seeded roles, so re-running it narrows existing databases to this matrix; custom roles are never touched.

### 2.2 SoD Conflict Invariants

Permission level — no non-admin role may hold both sides. Declared once in `App\Support\SodConflicts`, asserted against the seeded roles by `RolePersonaSodTest`, and enforced when a non-admin saves a role (`NoSodConflict` on `StoreRoleRequest` / `UpdateRoleRequest`) or assigns roles to a user (`NoStackedSodConflict` on `StoreUserRequest` / `UpdateUserRequest` merges the permissions of the submitted roles, so `manager` + `staff` on one user is refused). Admin is exempt:

| Control side | Execution side |
|---|---|
| `inventory.request.approve` / `reject` | `inventory.request.prepare` / `issue`, `inventory.stock.receive` / `issue` / `transfer` |
| `inventory.stock.adjust.approve` | `inventory.stock.adjust.request`, `inventory.stock.receive` / `issue` / `transfer` |

`inventory.stock.adjust` (direct adjustment) is break-glass and admin-only: a non-admin cannot save a role that holds it.

Same-actor guards — enforced in the service layer (inside the locked transaction), and in the Policy where the UI needs the same answer, for every actor including admin:
1. **Work Orders (SPK)**: approver / rejector MUST NOT be the creator or assigned technician.
2. **Inventory Requests**: approver / rejector MUST NOT be the requester; approver MUST NOT be the preparer or issuer.
3. **Stock Adjustments**: the approver / rejector MUST NOT be the requester (`adjust.request` and `adjust.approve` are held by different personas; a user who holds both still cannot decide their own request).
4. **Billing & Invoices**: invoice canceller MUST NOT be the invoice creator. Payment reverser MUST NOT be the payment recorder.
5. **Role & User Management**: non-administrators cannot assign the `admin` role, edit seeded roles (`RolePolicy`), or edit / delete an admin user (`UserPolicy`). A user who requested or reviewed a stock adjustment cannot be deleted (`UserDeletionGuard`; the foreign keys `requested_by` / `approved_by` are `RESTRICT`), so the evidence keeps its actor.

Accepted residuals (not permission-level splits): the manager keeps `spk.create` / `assign` and `billing.create` / `send` / `payment.record` next to approval, cancel and reverse, so those duties rely on the same-actor guards above. An administrator can still stack roles on one user (break-glass); direct permissions given to a single user are not checked.

### 2.3 Permission Grants by Role
| Domain / Capability | admin | manager | staff | technician | customer |
|---|:---:|:---:|:---:|:---:|:---:|
| `customer.manage` | Yes | Yes | - | - | - |
| `customer.create` / `update` | Yes | Yes | Yes | - | - |
| `customer.sales.assign` | Yes | Yes | Yes | - | - |
| `customer.sales.correct` | Yes | Yes | - | - | - |
| `inventory.stock.receive` / `transfer` | Yes | - | Yes | - | - |
| `inventory.stock.issue` | Yes | - | Yes | Yes | - |
| `inventory.stock.adjust.request` | Yes | - | Yes | - | - |
| `inventory.stock.adjust.approve` | Yes | Yes | - | - | - |
| `inventory.stock.adjust` (direct / break-glass) | Yes | - | - | - | - |
| `inventory.request.approve` / `reject` | Yes | Yes | - | - | - |
| `inventory.request.prepare` / `issue` | Yes | - | Yes | - | - |
| `spk.approve` / `reject` | Yes | Yes | - | - | - |
| `spk.start` / `submit` | Yes | - | Yes | Yes | - |
| `billing.cancel` / `payment.reverse` | Yes | Yes | - | - | - |
| `billing.create` / `send` / `payment.record` | Yes | Yes | Yes | - | - |
| `report.sales.view` | Yes | Yes | - | - | - |
| `report.export` | Yes | Yes | - | - | - |
| `report.technician` | Yes | Yes | - | Yes | - |

### 2.4 Stock adjustment workflow and break-glass
1. An operator (`inventory.stock.adjust.request`) posts `POST /admin/stocks/adjust`; a `pending` `stock_adjustment_requests` row records the requester, the quantity before and after, and the reason. Stock does not move.
2. A different user with `inventory.stock.adjust.approve` approves (`POST /admin/stocks/adjustments/{adjustment}/approve`) or rejects with a reason (`.../reject`). Approval applies the quantity under a row lock and writes the movement (`created_by` = approver) and the `stock_adjustment` audit entry with `adjustment_request_id`, `requested_by` and `approved_by`; a second decision on the same request is refused.
3. Admin break-glass: `POST /admin/stocks/adjust/direct` (`inventory.stock.adjust`, admin only) applies at once and writes the `stock_adjustment` audit entry with `break_glass: true`. It exists so a tenant with a single administrator is not blocked; every same-actor guard above still binds admin.

Audit events (log `inventory`): `stock_adjustment_requested`, `stock_adjustment` (approval or break-glass), `stock_adjustment_rejected`.

### 2.5 Deploying a persona change
Run `php artisan db:seed --class=RolePermissionSeeder --force`. It overwrites manual edits made to the five seeded roles (sync, not add) and leaves custom roles untouched; custom roles that need a new permission (for example `report.export`) are granted manually.
