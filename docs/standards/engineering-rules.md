# Engineering rules

- Prefer YAGNI and shortest working design.
- Business logic belongs in services.
- Controllers coordinate only.
- Repositories only when clear value exists.
- Use database constraints for integrity.
- Critical multi-table writes use `DB::transaction()`.
- Inventory stock changes only via immutable stock movements.
- Billing actions are auditable.
- Backend and frontend permission checks both required.
