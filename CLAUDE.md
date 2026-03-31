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

This is a **multi-role HR onboarding/offboarding management system** built on Laravel 12 with Blade + Tailwind CSS v4.

### Roles & Access
Four roles with distinct dashboards and capabilities:
- **User** — self-service profile/account management
- **HR** — employee lifecycle (onboarding, offboarding, employee records)
- **IT** — asset inventory, provisioning, offboarding IT tasks
- **SuperAdmin** — company management, role assignment

Route middleware enforces role access. Check `routes/web.php` and `app/Providers/AuthServiceProvider.php` for authorization gates/policies.

### Authentication
Uses a **custom authentication provider** (`WorkEmailUserProvider`) that authenticates against the employee's work email instead of a personal email. Configured in `config/auth.php` as `work_email_eloquent` provider. Password reset expiry is 60 minutes, timeout is 3 hours.

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

### Key Models
- `Employee` — central entity, has many relationships to all employee sub-tables
- `User` — auth model; linked 1:1 to `Employee`
- `Onboarding` / `Offboarding` — process tracking with status enums
- `ItTask` — IT work items assigned during onboarding/offboarding
- `AssetInventory` — master asset records; `AssetAssignment` links them to employees

### Scheduled Commands (`routes/console.php`)
Both run every minute:
- `employees:activate` (`ActivateEmployees`) — transitions employees to active status on start date
- `offboarding:notify` (`OffboardingNotifications`) — sends time-based offboarding email reminders

### Mail
8 Mailable classes in `app/Mail/`, each with a corresponding Blade template in `resources/views/emails/`. The default sender is `hr@claritas.com` (configured via `MAIL_FROM_ADDRESS`).

### Frontend
- Blade templates under `resources/views/` organized by role (`hr/`, `it/`, `user/`, `superadmin/`)
- Shared layout at `resources/views/layouts/app.blade.php`
- Tailwind CSS v4 via `@tailwindcss/vite` plugin — no `tailwind.config.js`; config lives in `resources/css/app.css`
- No JS framework; Alpine.js or vanilla JS where needed

### Testing
- PHPUnit with two suites: `Unit` (`tests/Unit/`) and `Feature` (`tests/Feature/`)
- Tests use SQLite in-memory database (`DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`)
- Array drivers for cache and mail in test environment

### Database
- MySQL in production/local (`claritas_onboarding`), SQLite in-memory for tests
- 44 migrations; the first 4 (prefixed `2024_01_`) define the core schema, subsequent `2026_03_` migrations are incremental enhancements
- Timezone: `Asia/Kuala_Lumpur` (set in `config/app.php`)

### Pending Route Change
`web.php.routes-to-add.txt` documents a planned registration route split. The routes in `routes/web.php` already reflect this update — the `.txt` file can be ignored.
