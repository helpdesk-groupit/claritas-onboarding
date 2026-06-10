# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Commands

```bash
# Initial setup
composer run setup           # composer install + migrate + npm install + build

# Development (runs server, queue, logs, and Vite concurrently)
composer run dev

# Build frontend assets
npm run build
npm run dev

# Testing
composer run test            # config:clear + phpunit
php artisan test             # run all tests
php artisan test --filter=TestName  # run a single test

# Database
php artisan migrate
php artisan migrate:fresh --seed

# Linting / formatting
./vendor/bin/pint            # Laravel Pint (PSR-12)
```

## Architecture Overview

This is a **multi-role HR platform** built on Laravel 12 with Blade + Tailwind CSS v4. It covers the full employee lifecycle plus several parallel modules:
- **Core:** Onboarding / Offboarding / Employee records
- **HR Operations:** Leave management, payroll & payslips, attendance tracking, expense claims (eClaim), EA forms
- **IT Operations:** Asset inventory, provisioning, AARF acknowledgements
- **Finance:** Accounting module (Chart of Accounts, AR/AP, GL, invoices, purchase orders, budgeting, tax returns, fixed-asset depreciation, bank reconciliation, AI invoice scanning) under `app/Http/Controllers/Accounting/` — ~38 models under `app/Models/Accounting/`, business logic in `AccountingService` / `AiAccountingService`
- **Helpdesk:** Ticketing module (see the detailed Ticketing section below) — company-scoped routing across 17 departments
- **Admin:** Company management, knowledge base, announcements, system overview/reports, role/permission assignment

### Reference Docs
- `FRONTEND-PATTERNS.md` — detailed reference of how each page's JavaScript works (event handlers, CSP compliance, dynamic form patterns, file upload patterns, per-page interaction map). **Consult before modifying any Blade view with JavaScript** to avoid breaking existing functionality.
- `docs/BACKUP-STRATEGY.md` — backup/restore design behind the `backup:run` command.
- `docs/THREAT-MODEL.md` — security threat model behind the middleware/services in the Security Architecture section.

**Critical rule (most common bug class in this codebase):** CSP blocks ALL inline event handler attributes — `onclick`, `onchange`, `oninput`, `onsubmit`, etc. — including those injected dynamically into `innerHTML` template literals. Always use `addEventListener` inside a nonce-protected `<script>` block. For dynamically created elements, use event delegation or `createElement` + `addEventListener`. Typical symptoms: button does nothing, form submit button stays disabled, validators never run, password visibility toggle fails.

### Roles & Access
Role groups with granular sub-roles:
- **HR** (`hr_manager`, `hr_executive`, `hr_intern`) — employee lifecycle; only `hr_manager` can edit records, download contracts, and access restricted documents
- **IT** (`it_manager`, `it_executive`, `it_intern`) — asset inventory, provisioning, offboarding IT tasks; view-only on employee/offboarding records
- **SuperAdmin** (`superadmin`) — company management, role assignment; effectively has HR Manager permissions
- **User** (`employee`) — self-service profile/account management
- `system_admin` — internal admin role; treated like HR Manager in most views

`User` model has coarse helpers (`isHr()`, `isIt()`, `isSuperadmin()`) and fine-grained capability methods (`canEditOnboarding()`, `canViewAssets()`, `canEditAarf()`, etc.) — always prefer these over raw role string comparisons. In Blade views, local `$canEdit` / `$canViewContracts` variables are derived from these helpers and used to gate UI elements.

Route middleware enforces role access. Check `routes/web.php` and `app/Providers/AuthServiceProvider.php` for authorization gates/policies.

### Authentication
Uses a **custom authentication provider** (`WorkEmailUserProvider`) that authenticates against the employee's work email instead of a personal email. Configured in `config/auth.php` as `work_email_eloquent` provider. Password reset expiry is 60 minutes, timeout is 3 hours.

**Two-factor auth (TOTP):** `pragmarx/google2fa-laravel` + `bacon/bacon-qr-code`. `TwoFactorController` handles setup/confirm/disable and a pre-auth `/two-factor-challenge` (session-gated, `throttle:5,1`). The `EnforceTwoFactor` middleware sits in the main `auth` group and redirects users who haven't completed their 2FA challenge. Superadmin can reset a user's 2FA via `superadmin.accounts.reset-2fa`.

### Employee Lifecycle Flow
```
OnboardingInvite → register/set-password → Employee (active) → Offboarding
```
- `Onboarding` model tracks the pre-hire onboarding process
- `Employee` model is the central entity; related data lives in separate models (PersonalDetail, WorkDetail, EmployeeEducationHistory, EmployeeSpouseDetail, EmployeeEmergencyContact, EmployeeChildRegistration, EmployeeContract)
- `EmployeeHistory` records lifecycle events
- `Offboarding` model tracks the exit process

### IT Asset Flow
```
AssetInventory → AssetAssignment (to employee) → AssetProvisioning → return/DisposedAsset
```
`Aarf` (Annual Asset Record Form) links assets to employees for acknowledgement via tokenized email links.

**Dual-storage gotcha:** Asset assignment date lives in two places — `asset_inventories.asset_assigned_date` (source for the asset listing edit form) and `asset_assignments.assigned_date` (source for AARF display). When updating one in `AssetController@update()`, sync the other, otherwise AARF will show a stale date.

### Key Models
- `Employee` — central entity, has many relationships to all employee sub-tables
- `User` — auth model; linked 1:1 to `Employee`
- `Onboarding` / `Offboarding` — process tracking with status enums
- `ItTask` — IT work items assigned during onboarding/offboarding
- `AssetInventory` — master asset records; `AssetAssignment` links them to employees

### Scheduled Commands (`routes/console.php`)
- `employees:activate` — every minute; activates employees on start date + sends welcome email, flushes `invite_staging_json` via `populateFromOnboarding()`
- `offboarding:notify` — every minute; time-based offboarding email reminders
- `leave:remind-managers` / `claims:remind` — daily at 9 AM
- `birthdays:send-wishes` — every minute (Asia/Kuala_Lumpur, `withoutOverlapping`); sends a virtual birthday e-card via `BirthdayWishMail` to every active employee whose `date_of_birth` matches today and `birthday_email_sent_year != current year`. Stamps `employees.birthday_email_sent_year = current year` after a successful send, so any number of reruns the same day are no-ops. Per-minute cadence ensures employees activated mid-day (rehires, start-date-on-birthday) get an almost-immediate wish. Feb 29 birthdays are delivered on Feb 28 in non-leap years. Template at `emails/birthday-wish.blade.php` is table-based + inline-styled for Outlook/Zoho/Gmail compatibility and uses no external images.
- `sweep:pending-weekly` — Wednesday midnight; scans all pending acknowledgements (employee profile consents, AARF forms, leave approvals, expense claim approvals) and sends targeted reminder emails to the responsible person
- `tickets:remind-stale` — hourly; emails + bell-notifies PIC (or department managers if unassigned) for any non-archived ticket idle 24h+. Throttled to one reminder per 24h via `tickets.last_reminder_sent_at`. Also auto-flips `Open → Pending` after 24h with no PIC.
- `security:audit-report` — hourly
- `log:verify-integrity` — daily at 3 AM via `LogIntegrity` service
- `backup:run` — daily at 2 AM (full encrypted backup, 30-day retention) + database snapshots daily (`--type=database`, 7-day retention)
- `system:refresh-metadata` — hourly; caches dashboard/knowledge-base metadata (1-hour TTL) via `SystemMetadataService`
- `system:check-updates` — daily at 6 AM; checks for dependency/system updates via `UpdateCheckerService` (24-hour cache)

### Security Architecture
- **`EnforceSingleSession`** middleware — prevents concurrent logins by rotating session tokens; kicks prior session when a new login occurs
- **`SecurityAuditMiddleware`** — logs 403s and rate anomalies to `SecurityAuditLog` model; plugs into `ThreatDetector` service for real-time threat analysis
- **`SecurityHeaders`** / **`ForceHttps`** — additional hardening middleware (controlled via `FORCE_HTTPS` env var)
- **`SecureFileController`** — serves private files with a `DIRECTORY_PERMISSIONS` map that enforces per-directory role checks; guards against path traversal
- **File validation in `AppServiceProvider`:** `valid_file_content` rule checks magic bytes against declared MIME type; `sanitize_image` rule strips EXIF/metadata via `ImageSanitizer` service
- **`ScanUploadsForMalware`** middleware (appended globally in `bootstrap/app.php`) — runs `MalwareScanner` against every `UploadedFile` on the request before any controller code. Blocks with HTTP 422 and records a `malware_blocked` row in `security_audit_logs` (best-effort — audit failures don't suppress the block). No-op on requests with no files. Hooked at the middleware layer rather than in `AttachmentProcessor` because not every upload (NRIC, profile pictures, leave attachments) routes through that service.
- **`MalwareScanner`** service — two independent layers, either flag rejects:
  - *Heuristic (always on):* samples first 8 KB + last 1 KB of the file; matches EICAR, embedded `<?php`/`<?=`, webshell function chains (`eval(base64_decode)`, `eval($_REQUEST)`, shell-exec on user input, `preg_replace /e`, `assert($_REQUEST)`, `create_function`), ASP/JSP runtime calls, and Office macro autoexec paired with Shell calls. Patterns are intentionally narrow — false positives break user workflows.
  - *ClamAV (optional):* if `CLAMAV_HOST` is set (see `config/services.php`), also scans via `clamd` INSTREAM protocol over TCP. Network errors degrade silently to heuristic-only and log at warning level. Defaults: port 3310, 5s connect timeout, 30s stream timeout.
- Upload rate-limiting: `throttle:uploads` (10 uploads/minute per user/IP)

### File Storage
Two disks: `local` (private: `storage/app/private`, served via `SecureFileController`) and `public` (public: `storage/app/public`). Sensitive files (NRIC, contracts, certificates) go to the private disk and are served with role-gated access checks.

### Mail & Notifications
Mailable classes live in `app/Mail/` with Blade templates in `resources/views/emails/`. Database notifications (bell icon) live in `app/Notifications/` and use Laravel's `Notification` facade with the `database` channel. Default sender is `hr@claritas.com` (configured via `MAIL_FROM_ADDRESS`).

Notable mail classes:
- `OnboardingEditNotificationMail` — plain notification sent when HR edits an onboarding record (no acknowledgement required)
- `EmployeeConsentRequestMail` / `ConsentRequestMail` — full re-acknowledgement flow with token link, used for **employee listing and profile** edits only
- `WelcomeNewHire` — sent by `ActivateEmployees` command on start date
- `SuspiciousActivityAlert` / `SecurityAuditMail` — triggered by `ThreatDetector`
- `ClaimApprovedMail`, `ClaimSubmittedMail`, `ClaimReminderMail` — eClaim workflow
- `LeaveApplicationNotifyMail`, `LeaveApprovalNotifyMail`, `PendingLeaveReminderMail` — leave workflow
- `EaFormReadyMail` — payroll EA form notification
- `BirthdayWishMail` — daily birthday e-card to the employee's work email (table-based, inline-styled, no external images for max client compatibility); sent by `birthdays:send-wishes` and idempotent via `employees.birthday_email_sent_year`
- `WeeklyPendingSweepMail` — weekly sweep reminder for all pending acknowledgements/approvals (consent, AARF, leave, claims); sent by `sweep:pending-weekly` on Wednesdays
- `TicketCreatedMail` / `TicketAssignedMail` / `TicketResolvedMail` / `TicketReminderMail` / `TicketNewMessageMail` — ticket lifecycle emails, paired with matching `TicketRaisedNotification` / `TicketAssignedNotification` / `TicketResolvedNotification` / `TicketReminderNotification` / `TicketUnassignedNotification` / `NewTicketMessageNotification` for the in-app bell. The chat-message email and bell are dispatched together from `TicketMessageController::store()` to the same recipient set (raiser + PIC, never the sender) so the two channels stay in sync; email failures are logged but don't break the chat write.

### Frontend
- Blade templates under `resources/views/` organized by role (`hr/`, `it/`, `user/`, `superadmin/`) plus `accounting/` and `reports/`
- Shared layout at `resources/views/layouts/app.blade.php`
- Tailwind CSS v4 via `@tailwindcss/vite` plugin — no `tailwind.config.js`; config lives in `resources/css/app.css`
- UI framework: **Bootstrap 5.3.2** + Bootstrap Icons 1.11.3, loaded globally from jsdelivr CDN
- Per-page CDN libraries: **Select2 4.1.0-rc.0** (onboarding form), **Chart.js 4.4.7** (accounting & executive dashboards)
- No JS framework; vanilla JS only. Always escape user-entered values before `innerHTML` insertion using the project-standard `escHtml(s)` / `obEsc(s)` helpers.

### Testing
- PHPUnit 11 with two suites: `Unit` (`tests/Unit/`) and `Feature` (`tests/Feature/`)
- Tests run against a **separate MySQL database** `claritas_onboarding_test` (`DB_CONNECTION=mysql`, `127.0.0.1:3306`, user `root`) — configured in `phpunit.xml`, not SQLite. The test DB must exist locally before running the suite.
- Array drivers for cache, mail, and session; `QUEUE_CONNECTION=sync`; `BCRYPT_ROUNDS=4` in the test environment

### Database
- MySQL everywhere: `claritas_onboarding` for production/local, `claritas_onboarding_test` for the test suite
- ~98 migrations spanning 2024-01 to 2026-06; the first 4 (prefixed `2024_01_`) define the core schema, subsequent `2026_03_` through `2026_06_` migrations are incremental enhancements (the bulk of the ticketing, leave/payroll, accounting, and security work)
- Timezone: `Asia/Kuala_Lumpur` (set in `config/app.php`)

### Onboarding Staging JSON (`invite_staging_json`)
Sections F–I (Education, Spouse, Emergency Contacts, Children) submitted by a new hire via the public invite form are stored as a JSON blob in `personal_details.invite_staging_json`. They are **not** immediately written to their relationship tables. The `ActivateEmployees` command calls `populateFromOnboarding()` on the employee's start date to flush this staging data into the proper tables (`employee_education_histories`, `employee_spouse_details`, etc.).

- When displaying Sections G/H/I for an employee whose relationship tables are still empty, `resources/views/partials/employee-extra-sections-view.blade.php` reads from `invite_staging_json` as a fallback.
- When HR edits an onboarding record and only changes Sections B or C (Work/Asset), `buildStagingJson()` returns the existing JSON unchanged to prevent wiping staging data.

### Consent & Edit Log Flows (two distinct flows)
| Context | Log model | Flow |
|---|---|---|
| Onboarding record edits (pre-hire) | `OnboardingEditLog` | Notification email only — `consent_required = false`, no token |
| Employee listing / profile edits | `EmployeeEditLog` | Full re-acknowledgement: token generated, expiry set, consent link emailed |

Only Sections A, F, G, H, I trigger any email for onboarding edits. The employee consent flow is always triggered for relevant edits in `EmployeeController`.

### Multi-file Storage Patterns
- **NRIC/Passport:** `personal_details.nric_file_paths` (JSON array). In employee records, mirrored to `employees.nric_file_paths`. Keep/remove controlled via `nric_keep_paths[]` hidden inputs on edit forms.
- **Education certificates:** `employee_education_histories.certificate_paths` (JSON array, max 5). Legacy single-file column `certificate_path` is kept as the first entry for backwards compatibility. Keep/remove controlled via `edu_cert_keep[i][]` hidden inputs; new files use the DataTransfer API to attach File objects to a hidden `<input type="file">`.

### IT vs HR Offboarding Views
There are two separate view paths for offboarding detail:
- `hr.offboarding.show` — accessed by HR staff via `hr.offboarding.index`
- `it.offboarding-show` — accessed by IT staff via `it.offboarding.index`

Both views display Sections F–I via `partials.employee-extra-sections-view`. The IT view is read-only and locks contract/handbook/orientation documents with an "HR only" badge.

### Key Services (`app/Services/`)
- `SystemMetadataService` — aggregates data for executive dashboards and knowledge base; results cached 1 hour
- `ThreatDetector` — tracks login failures, rate anomalies, unauthorized access; configurable detection windows
- `LogIntegrity` — verifies security audit log chain integrity (HMAC chaining via `LOG_INTEGRITY_KEY`)
- `ImageSanitizer` — strips EXIF metadata from uploaded images
- `AccountingService` / `AiAccountingService` — accounting module business logic and AI invoice scanning
- `AttachmentProcessor` — centralised secure-storage + image-compression pipeline for ticket uploads (resizes images to 1920px max width via GD, re-encodes to strip EXIF, moves PDFs/other files as-is). Used by both `TicketAttachment` (creation) and `TicketMessageAttachment` (chat).
- `SecurityScoreService` — computes a cached (1-hour TTL) security-posture score/breakdown from `SecurityAuditLog` + config/schema checks; surfaced on the admin security dashboard
- `UpdateCheckerService` — checks for dependency/system updates via HTTP; results cached 24 hours, refreshed by `system:check-updates`

### Ticketing Module
Internal helpdesk-style tickets with company-scoped routing across 17 departments. Self-service at `/tickets` (raise + view own); management at `/tickets/manage` (PIC inbox).

**Status lifecycle** (`Ticket::STATUSES`):
```
Open → In Progress → Resolved (terminal, auto-archived)
                  ↘  Closed   (terminal, manual close without resolution)
Open → Pending (auto-flipped after 24h with no PIC, by tickets:remind-stale)
```
`ACTIVE_STATUSES = [Open, In Progress, Pending]`; `ARCHIVED_STATUSES = [Resolved, Closed]`. `last_reminder_sent_at` throttles reminder emails to 1 per 24h.

**Department types — two PIC eligibility models** (`Ticket::DEPARTMENTS`):
- **App-role-gated** (HRA, Group IT, Finance, Admin) — eligibility = `users.role` ∈ `DEPARTMENT_MANAGER_ROLES[$dept]`. Some have intern extras (`DEPARTMENT_PIC_EXTRA_ROLES`) eligible for PIC but not for "manager" notifications.
- **Work-role-gated** (the other 14: Community, Consulting, Content, Design, Digital, Ecommerce, KOL, Management, Marketing, Media, Production, Projects, Sales, Tech) — eligibility = `employees.work_role = 'manager'` AND `employees.department = <dept>`. `superadmin`/`system_admin` are catch-all eligible.

**Department × Company access** (`department_company_access` pivot, configured at `/superadmin/department-settings`):
- Effective served-companies for a dept = **auto-derived members** (companies where any qualifying user/employee works) ∪ **explicit pivot extras**.
- Empty result = "serves all companies" (graceful default for unconfigured depts).
- Routing helpers all live as static methods on `Ticket`: `companiesServingDepartment()`, `companyNamesServingDepartment()`, `departmentsForCompany()`, `picPoolForDeptAndCompany()`. Always use these — don't reimplement the union logic.

**`Ticket::scopeVisibleTo(User)`** for the management page — the model is "dept-team across served cluster", not company-isolated:
- `superadmin`/`system_admin` → all tickets.
- Managers (incl. executives) → tickets where, for some managed dept, `tickets.department = <dept>` AND `tickets.company_id` ∈ `companiesServingDepartment(<dept>)`. So a Tech manager at Claritas — when Tech serves Claritas+Enlinea+Nuren — sees Tech tickets from all three. Per-managed-dept OR clauses, so a multi-dept manager gets each dept's own cluster.
- Non-managers (interns with assigned tickets) → only tickets `assigned_to = user.id`. The assignment itself is the gate; whoever assigned the intern accepted the cross-company exposure.
- A user's own RAISED tickets are NOT included — those live on the self-service page (`/tickets`), filtered separately.

**`Ticket::picPoolForDeptAndCompany(dept, companyId, includePicExtras)`** has TWO paths to inclusion (OR'd), both restricted to the dept's served-companies cluster:
- **Path 1 — Manager set** (always evaluated): work-role-gated → `employees.work_role = 'manager'` AND `employees.department = dept`; app-role-gated → `users.role` ∈ `DEPARTMENT_MANAGER_ROLES[dept]` (plus `DEPARTMENT_PIC_EXTRA_ROLES` like interns when `includePicExtras = true`).
- **Path 2 — Department-membership fallback** (only when `includePicExtras = true`): any user with `employees.department = dept` (regardless of `work_role` / `users.role`) at a served company. Lets non-manager team members be assignable as PIC.
- Plus `superadmin`/`system_admin` always (catch-all).

Path 2 is intentionally OFF when `includePicExtras = false`, so `managersForNotification()` (used for new-ticket emails + stale reminders) stays scoped to the strict manager set. `eligiblePicQuery()` (the assign-PIC dropdown) passes `true`, so the broader pool surfaces only there.

Path 2 requires the `employees.department` value to **exactly match** the canonical dept name (`'HRA'`, `'Group IT'`, `'Finance'`, `'Admin'`, plus the 13 work-role-gated names). `employees.department` is free-text — audit/canonicalise periodically.

**Ticket department re-route** (`/tickets/{id}/edit-admin`): the only editable field is `department`, used to fix mis-filed tickets (e.g. raiser picked the wrong dept). **Authorization is two-layer:**
- Server-side (`TicketController::authorizeEdit()`): superadmin/system_admin OR a manager of the ticket's *current* department (`User::canManageTicketsForDepartment()`). A Tech manager can re-route a Tech ticket, but not a KOL ticket.
- UI-side: the Edit button on the ticket detail page is gated by `$canManage` — the same flag that gates Assign-PIC and Update-Status. Reached only when the user navigated from `/tickets/manage` (i.e. `?from=manage`). Opening the ticket from `/tickets` (My Tickets) keeps the page read-only, even for superadmin, by design.

**Side-effects on save:** PIC and `assigned_at` cleared, status reset to `Open`, and `TicketCreatedMail` + `TicketRaisedNotification` dispatched to the *new* department's manager pool (including unregistered-manager fallback). Old dept's managers are not re-notified — the ticket simply leaves their inbox by virtue of the dept-scoped visibility query.

**Edit log** (`ticket_edit_logs` — FK to `tickets` + `users`, `changes` JSON, optional `note`): persists every save. Rendered as a card on the ticket detail page for **anyone who can view the ticket** — managers, the raiser, the PIC, and admins all see the history. The show page is already auth-gated via `authorizeView()`, which is the effective visibility set.

**Unregistered-manager email fallback** (`Ticket::unregisteredManagersForNotification()`): the User-keyed manager pool misses managers who have HR records but haven't created their User account yet. The fallback returns Employee rows where `work_role = 'manager'`, `department = ticket dept`, `active_until IS NULL`, `company` in served cluster, and either no linked User OR the User is `is_active = false`. It applies **only to work-role-gated depts** (app-role-gated depts identify managers via `users.role`, which doesn't exist pre-registration). Used by `TicketController::store()` and `tickets:remind-stale` for **email only** — no in-app bell, because `notifications.notifiable_id` FKs to `users.id`. The "no users row" condition prevents double-emailing registered managers. `TicketCreatedMail` / `TicketReminderMail` constructors now accept `User|Employee` for `$recipient`; the view receives a computed `$recipientName` string so it doesn't have to discriminate.

**Intern capabilities** (HRA, Group IT only): can be assigned PIC, can chat + update status on assigned tickets, but CANNOT assign/reassign PIC, see full department inbox, or receive "new ticket raised" notifications (those go to managers only via `managersForNotification()`).

**Ticket detail page — manage controls are navigation-context-gated:**
- The same `/tickets/{id}` page is reached from both `/tickets` (My Tickets) and `/tickets/manage`. The Add/Remove PIC and Update Status (as a manager) controls render only when the user navigated from the Management page — links there pass `?from=manage`. Links from My Tickets don't pass it.
- `TicketController::show()` reads the `from` query param: `$canManage = $hasManageRole && $request->query('from') === 'manage'`. So a Tech manager opening their own raised ticket from My Tickets sees a read-only view; opening someone else's Tech ticket from Ticket Management gives them full controls. The action handlers (`assignPic`, `updateStatus`) still enforce the manage-role check server-side, so a hand-crafted `?from=manage` URL doesn't grant manage rights to a non-manager.
- The PIC's own status-update path is independent — `@if($canManage || $ticket->assigned_to === Auth::id())` — so a non-manager PIC can still update status on their assigned tickets regardless of source page.

**Page access — managers vs everyone else:**
- `/tickets/manage` (Ticket Management page) is gated by `User::canAccessTicketManagement()`, which is now **strict**: superadmin/system_admin, or `users.role` ∈ `[hr_manager, it_manager, finance_manager]`, or `employees.work_role = 'manager'`. Executives, interns, and regular employees do NOT get this page even if they're PIC-eligible.
- Non-managers see their assigned tickets via the **Assigned to Me** tab on `/tickets` (My Tickets), placed between Active and Archived. The tab queries `tickets.assigned_to = user.id` AND `status IN ACTIVE_STATUSES` — terminal (Resolved/Closed) assigned tickets are excluded here and fall through to the Archived tab. The **Archived** tab on `/tickets` shows terminal tickets the user either RAISED **or** is PIC of (`(user_id = me OR assigned_to = me) AND status IN ARCHIVED_STATUSES`), so a PIC keeps sight of their finished assigned work. All three tab badge counts mirror exactly what their tab renders.
- The stricter `canAccessTicketManagement()` is intentionally narrower than `Ticket::isManagerOf()` — the latter still includes executives so they continue to receive new-ticket notifications and dept-team visibility.

**Resolution-time metric — measured from `assigned_at`, not `created_at`:**
- `tickets.assigned_at` (added by `2026_05_07_000001_add_assigned_at_to_tickets`) is set in `TicketController::assignPic()` when a PIC is assigned and cleared when one is removed. Cards 2 and 3 (PIC perf + Department Health) use `TIMESTAMPDIFF(MINUTE, COALESCE(assigned_at, created_at), resolved_at)` so a ticket that sat unassigned for days doesn't count against the PIC. `Ticket::timeToResolve()` mirrors this via `($this->assigned_at ?? $this->created_at)->diff($this->resolved_at)`.
- COALESCE keeps legacy tickets (resolved before the column existed) showing the old "creation → resolution" time so historical numbers don't shift on day one. New assignments going forward measure correctly.
- If you want to backfill historical data: `UPDATE tickets SET assigned_at = updated_at WHERE assigned_to IS NOT NULL AND assigned_at IS NULL` is a rough proxy (uses last status-change time as the assignment proxy). Optional — only if accurate historical numbers matter.

**PIC analytics on the Assigned to Me tab:**
- The same three analytics cards from the Ticket Management page are also rendered above the listing when `$scope === 'assigned'` on `/tickets`. Built by `TicketController::buildPicAnalytics($user)` — same return shape as `buildManagerAnalytics()`, so the existing `tickets.partials.analytics-card-2-pic-times` and `analytics-card-3-dept-health` partials are reused unchanged.
- Scope is per-user, not per-team:
  - **Card 1 (priority)** — only tickets where `assigned_to = $user->id` AND status in active set.
  - **Card 2 (PIC perf)** — single row: the user's own avg `TIMESTAMPDIFF(MINUTE, created_at, resolved_at)` across their Resolved tickets.
  - **Card 3 (dept health)** — single row: `tickets.department = $user->employee->department`, regardless of who PIC'd, mapped to a tier via `Ticket::healthTier()`. Skipped if the user has no `employees.department`.
- `availableCompanies` is intentionally empty for the PIC view — no per-company filter dropdown rendered. The two card partials guard the `<select>` with `@if(!empty($analytics['availableCompanies']))` so the dropdown only appears on the manage page.

**Ticket numbering:** `TIC-YYYY-NNNN` per-year sequence, generated atomically in a `lockForUpdate()` transaction (`Ticket::generateTicketNumber()`, auto-fired in `booted()::creating`). Don't pre-set `ticket_number`.

**Standardised subjects:** `Ticket::DEPARTMENT_SUBJECTS` is a controlled vocabulary used by the Raise New Ticket dropdown so analytics aggregate cleanly. Edit the constant freely — no migration needed; existing tickets keep their text untouched.

**Attachments:** Both creation attachments (`TicketAttachment`) and chat attachments (`TicketMessageAttachment`) flow through `AttachmentProcessor::store()` into private storage. Routes are upload-throttled (`throttle:uploads`).

**Department Settings UI flow** (`/superadmin/department-settings`, superadmin/system_admin only):
- Layout is a **company-first accordion** mirroring the Ticket Management page (`.company-section` / `.company-header` / `.company-body`). Each company expands to show **only the departments that actually exist at that company** — strict, member-based definition. Departments without members at a company simply don't appear there; this page cannot fabricate or "add" a department to a company.
- "Exists at this company" = a user/employee with the dept's role/work_role works at that company (auto-derive). Extras (cross-company assignments via the pivot) are **not** counted as existence — an Extra is just a routing rule that lives at the *source* company.
- Each existing dept row carries an **Also serves these other companies (Extras)** chip cloud — clickable chips for every *other* registered company. Clicking a chip toggles whether this dept also handles that company's tickets. Saved as rows in `department_company_access` (`department`, `company_id`).
- Multiple-auto-derive sync: if the same dept auto-derives at two companies, both rows render the same chip cloud. JS keeps the chips synced so toggling in one row mirrors to the other (single underlying pivot row).
- Form posts: `assignments[<department>][] = <other_company_id>` — same backend shape as before. Auto-served existence has no input (it's derived, not stored here).
- Update strategy is **wipe-and-rebuild** — `DepartmentCompanyAccess::query()->delete()` then re-insert from the submitted array. Safe because the table is small and admin-edited; do not adapt to per-row diffs without reason.
- The receiving company's section does NOT list inbound Extras. So if Tech (Claritas) is extras-assigned to Nuren, Nuren's section won't show a "Tech" row — Nuren doesn't have its own Tech members. The Extra is visible only on Claritas's Tech row's chip cloud. (The New Ticket form, however, still shows Tech in Nuren's Department dropdown via `companiesServingDepartment()`.)

**Strict-membership filtering** (matches Department Settings UX):
- `Ticket::departmentsForCompany($companyName)` — used by the New Ticket form's Department dropdown. Returns only depts where the company is in the served-companies cluster (auto ∪ pivot extras). Implicit-serves-all fallback **not** applied — fully-unconfigured depts are intentionally invisible per-company.
- `Ticket::departmentServesCompany($dept, $companyName)` — same strict definition, used for server-side validation in `TicketController::store()` so the bounce matches what the dropdown shows.
- Other helpers (`scopeVisibleTo`, `picPoolForDeptAndCompany`) keep the implicit-serves-all default for routing safety on fully-unconfigured depts. Superadmin/system_admin bypass these filters at the controller and always see every department.

**User-manual modals + public help pages**:
- Each ticketing-module page (My Tickets, Ticket Management, Department Settings) has a User Manual button that opens an in-app Bootstrap modal.
- Modal partial is split in two: `_user-manual-<topic>.blade.php` (modal wrapper + footer buttons) and `_user-manual-<topic>-body.blade.php` (CSS + content sections, with generic `Company A` / `Company B` placeholders, never real customer names).
- The same `*-body.blade.php` partial is also included by a standalone public Blade view (`help.<topic>`) extending [`layouts/help.blade.php`](resources/views/layouts/help.blade.php) — single source of truth for the manual content.
- Routes at `/help/my-tickets`, `/help/ticket-management`, `/help/department-settings` — see [`HelpController`](app/Http/Controllers/HelpController.php). **Auth-gated** (sit inside the main `auth` middleware group in `routes/web.php`); URLs are shareable but viewers must log in. Layout still emits `noindex,nofollow`. Unauthenticated visitors land on the login page.
- Each modal footer carries a **Copy share link** button (`.um-share-btn` with `data-share-url`) and an **Open in new tab** anchor pointing to the public help URL. The clipboard-copy JS lives once in [`partials/_user-manual-share-js.blade.php`](resources/views/partials/_user-manual-share-js.blade.php) (guarded by `@once` so multiple modal includes don't push it twice).
- Don't put real company names back into the body partials — the public pages would expose them. Keep the placeholder vocabulary (`Company A`, `Company B`, `Company C`, `Company D`).

### Notifications / Bell Icon
Uses Laravel's standard `notifications` table via the `Notifiable` trait on `User`. All ticket notifications are `database`-only (no mail channel — email goes via separate `Mail::to()->send($mailable)` calls inside controllers/commands).

**Notification payload contract** (returned from `toDatabase()`): all ticket notifications include the same shape so the bell can render them generically:
```php
['event', 'ticket_id', 'ticket_number', 'department', 'priority', 'subject',
 'icon' /* bi-* class */, 'color' /* bootstrap color */, 'message', 'url']
```
When adding a new notification, follow this shape — the bell JS reads `data.icon`, `data.color`, `data.message`, `data.url` only.

**Bell UI** lives in [`resources/views/layouts/app.blade.php`](resources/views/layouts/app.blade.php) inside a single nonce-protected `<script>` block (CSP-compliant, ~line 1195). Behaviour:
- Polls `GET /notifications` every 60s, **only when `document.visibilityState === 'visible'`** (no background traffic on hidden tabs).
- Renders the latest 10 with `escapeHtml()` on every dynamic value (in line with the project-wide rule against unescaped `innerHTML`).
- Click on an item: fires `POST /notifications/{id}/read` (fire-and-forget, no `preventDefault` — navigation to `data.url` happens normally).
- "Mark all read" button → `POST /notifications/read-all`.
- The `NotificationController` JSON feed returns `{ notifications: [...], unread_count: N }`; both `index` and `markAllRead` return the updated unread count so the badge stays in sync without an extra fetch.

### Pending Route Change
`web.php.routes-to-add.txt` documents a planned registration route split. The routes in `routes/web.php` already reflect this update — the `.txt` file can be ignored.
