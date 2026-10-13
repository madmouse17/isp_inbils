# BACKEND: canTechnicianReport shared prop

Status: done
Role: backend
Blocked by: none
Spec: `../spec.md`

## Scope

- The Reporting module shares `canTechnicianReport` (lazy closure) from its service provider using the rule of `TechnicianReportAccess` without aborting (technician role + permission; manager + permission + active employee profile in an organization of the effective company).
- Feature tests per case: technician, manager with organization, manager without, staff, guest/no company.

## Acceptance criteria

- [x] Prop matches `TechnicianReportAccess` in every case; no Core → Module import.

## Comments

- 2026-10-13 Backend: TechnicianReportAccess::allows() shares logic with companyId(); ReportingServiceProvider::boot shares lazy canTechnicianReport; tests/Feature/Reporting/TechnicianReportFlagTest (technician, admin, manager+org, manager w/o org, staff, no company, guest) green.
