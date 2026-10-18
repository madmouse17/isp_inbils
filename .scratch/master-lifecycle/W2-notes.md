# master-lifecycle W1 → W2 hand-off notes (Main, 2026-10-18)

W1 accepted: W1-MECH (27 tests), W1-UI (tsc/lint/build), W1-VERSIONS (39 + 8 tests, 3 approved test edits), W1-SNAPSHOT-MIG (5 tests). Dev DB `inbils` NOT migrated yet (only `--pretend`); Main migrates after W2 with a mysqldump first (design §8.5).

Carry into W2 briefs:
- W2-SERVICE-BE: wrap `ServicePackageController::store` in `DB::transaction` (package without v1 if the version insert fails).
- W2-SPK-BE: add `organization_unit_id` to `WorkOrder::$fillable` before writing the snapshot in `SpkService::approve`.
- W2-NETWORK-BE: `NetworkSyncTest.php:227,242` and `ProvisioningFlowTest.php:653` move a subscription with `forceFill(['service_package_id'])->saveQuietly()`; once provisioning reads `packageVersion.speedProfile`, those tests must set `service_package_version_id` too (owned by W2-NETWORK-BE).
- W2-SUBS-BE: nullable profile branches in `CustomerSubscriptionResource` are unreachable now (NOT NULL FKs, D6).
- `LifecycleService` extras beyond the contract: activate throws on models without an active flag; no-op + no audit when the flag already has the value; `destroyIfUnreferenced` throws for models whose `lifecycleReferences()` is null.
- Integrity lane: `PreventDuplicateWebSubmit` is OFF in `testing` (`config('security.duplicate_submit.enabled')`), ON in `e2e`; W3-E2E specs must not repeat an identical write within 10 s.
- Cross-lane gates (design §8.4): W2 slices holding integrity-first files start only after the matching integrity W2 slice is accepted.
