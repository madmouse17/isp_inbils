# Domain Docs

How the engineering skills should consume this repo's domain documentation when exploring the codebase. Layout: **single-context**.

## Before exploring, read these

- **`GLOSSARY.md`** at the repo root, if it exists.
- **`docs/architecture/*.md`**: overview, service layer, domain boundaries, modules, folder structure, database.
- **`docs/business/*.md`**: per-module business spec (customer, inventory, network, billing, ticketing, approval flow, …).
- **`docs/architecture/decisions/`**: ADRs (`ADR-NNN.md`). Read the ones that touch the area you are about to work in.

If a file doesn't exist, **proceed silently**. Don't flag its absence; don't suggest creating it upfront. The `/domain-modeling` skill creates `GLOSSARY.md` and new ADRs lazily when terms or decisions actually get resolved; new ADRs go in `docs/architecture/decisions/` with the next `ADR-NNN.md` number.

## Use the glossary's vocabulary

When your output names a domain concept (in an issue title, a refactor proposal, a hypothesis, a test name), use the term as defined in `GLOSSARY.md` or the business docs (e.g. SPK, POP, Stock Card). Don't drift to synonyms the docs explicitly avoid.

If the concept you need isn't documented yet, that's a signal: either you're inventing language the project doesn't use (reconsider) or there's a real gap (note it for `/domain-modeling`).

## Flag ADR conflicts

If your output contradicts an existing ADR, surface it explicitly rather than silently overriding:

> _Contradicts ADR-002 (Inertia-as-Island), but worth reopening because…_
