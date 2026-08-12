# Frontend Patterns & Interaction Reference

> **Purpose:** Living reference for how each page's JavaScript works.
> Consult before modifying any view to avoid breaking existing functionality.
> Last updated: 2026-04-15

---

## CSP Policy (SecurityHeaders middleware)

```
script-src 'self' 'nonce-{$nonce}' 'unsafe-hashes' https://cdn.jsdelivr.net
```

**Rules:**
- `<script nonce="{{ $cspNonce ?? '' }}">` blocks execute normally
- `addEventListener` inside a nonce'd script block is always safe
- Inline `onclick="..."` / `onchange="..."` attributes are **blocked** by CSP
- Dynamically generated HTML with `onclick` in template literals is **blocked**
- When adding new event handlers, always use `addEventListener` or event delegation

**Safe pattern (use this):**
```html
<button type="button" id="myBtn">Click</button>
<script nonce="{{ $cspNonce ?? '' }}">
document.getElementById('myBtn').addEventListener('click', myFunction);
</script>
```

**Unsafe pattern (avoid):**
```html
<button onclick="myFunction()">Click</button>  <!-- blocked by CSP -->
```

**For dynamically created buttons (use event delegation or createElement):**
```javascript
// GOOD: createElement + addEventListener
const btn = document.createElement('button');
btn.addEventListener('click', function() { removeItem(i); });
row.appendChild(btn);

// GOOD: Event delegation
document.getElementById('list').addEventListener('click', function(e) {
    const rm = e.target.closest('[data-remove]');
    if (rm) removeItem(parseInt(rm.dataset.remove));
});

// BAD: inline handler in template literal
list.innerHTML += `<button onclick="removeItem(${i})">X</button>`;
```

---

## External Libraries

| Library | CDN | Used In |
|---------|-----|---------|
| Bootstrap 5.3.2 | jsdelivr | layouts/app.blade.php (global) |
| Bootstrap Icons 1.11.3 | jsdelivr | layouts/app.blade.php (global) |
| Select2 4.1.0-rc.0 | jsdelivr | hr/onboarding/page.blade.php |
| Chart.js 4.4.7 | jsdelivr | accounting/dashboard, executive-dashboard |

---

## Common JS Patterns

### 1. HTML Escaping

Two functions exist — use whichever is already in scope:
```javascript
function obEsc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function escHtml(s) { /* identical */ }
```
Always escape user-entered values before inserting into innerHTML.

### 2. DataTransfer API (file inputs)

Used to programmatically set `input.files` when collecting files from an in-memory array:
```javascript
const dt = new DataTransfer();
filesArray.forEach(f => dt.items.add(f));
const inp = document.createElement('input');
inp.type = 'file'; inp.name = 'field_name[]'; inp.multiple = true;
inp.style.display = 'none';
inp.files = dt.files;
container.appendChild(inp);
```
**Files:** onboarding/page.blade.php, user/profile.blade.php, employees/edit.blade.php

### 3. Dynamic Form Arrays (hidden inputs)

Sections with "Add to List" use in-memory JS arrays synced to hidden inputs:
```javascript
// In-memory store
let entries = [];

// After push, render hidden inputs
entries.forEach((e, i) => {
    h.innerHTML += `<input type="hidden" name="items[${i}][name]" value="${escHtml(e.name)}">`;
});
```

**Naming conventions:**
- Education: `edu_qualification[]`, `edu_institution[]`, `edu_year[]`, `edu_certificate[]`
- Spouse: `spouses[i][name]`, `spouses[i][nric_no]`, `spouses[i][tel_no]`, etc.
- Emergency: `emergency[order][name]`, `emergency[order][tel_no]`, `emergency[order][relationship]`
- Accounting line items: `items[i][description]`, `lines[i][account_id]`

### 4. Bootstrap Modal Re-open on Validation Error

```javascript
@if($errors->any())
document.addEventListener('DOMContentLoaded', function() {
    new bootstrap.Modal(document.getElementById('modalId')).show();
});
@endif
```

### 5. Conditional Section Toggle (Marital Status -> Spouse)

Disables/enables all inputs inside a container based on a dropdown value:
```javascript
function toggleSection(val) {
    const section = document.getElementById('sectionId');
    const isActive = val === 'married';
    section.querySelectorAll('input, select, textarea, button').forEach(el => {
        el.disabled = !isActive;
    });
    section.style.opacity = isActive ? '1' : '0.4';
}
```
**Watch out:** This disables ALL buttons inside the section, including "Add to List" buttons. If you add a new button inside a toggle-able section, it will be disabled when the section is inactive.

### 6. Company -> Office Location Autofill

Company `<option>` elements carry `data-address` attribute. On change and on page load, the address is copied to the office location input:
```javascript
function autofillOfficeLocation(selectEl, targetId) {
    const selected = selectEl.options[selectEl.selectedIndex];
    const target = document.getElementById(targetId);
    if (!target || !selected || !selected.value) return;
    target.value = selected.dataset.address || '-';
}
```

### 7. Event Delegation for Dynamic Rows

Used in accounting forms for line items:
```javascript
tbody.addEventListener('click', function(e) {
    if (e.target.closest('.remove-item')) {
        e.target.closest('tr').remove();
        recalc();
    }
});
```

---

## Page-by-Page Reference

### HR Module

#### `hr/onboarding/page.blade.php` — New Onboarding (modal form)
- **Modal:** `#addOnboardingModal` (line 264), re-opens on validation errors
- **Sections F/G/H:** Add-to-list with in-memory arrays (`obEduEntries`, `obSpouseEntries`, `obEcEntries`)
- **Add buttons:** `#obAddEduBtn`, `#obAddSpouseBtn`, `#obAddEcBtn` — bound via addEventListener
- **Remove buttons:** Created via `document.createElement('button')` + addEventListener (CSP-safe)
- **Company select:** `#addOBCompanySelect` — triggers `autofillOfficeLocation` and `filterManagersByCompany`
- **Manager select:** `#reporting_manager` — triggers `fetchManagerEmail`
- **Marital status:** `#obMaritalStatus` — `onchange` toggles spouse section (disables all inputs)
- **Asset cards:** `onclick="toggleAsset('...')"` — inline handler (legacy, still works with unsafe-hashes)
- **DOMContentLoaded (4):** office location sync, Google ID sync, modal re-open, spouse section toggle
- **Select2:** Used for searchable dropdowns
- **File handling:** Certificate files via DataTransfer API into `edu_certificate[]`

#### `hr/onboarding/edit.blade.php` — Edit Onboarding
- Similar to page.blade.php but as a full-page form (no modal)
- Same section F/G/H patterns
- Company select: `#editOBCompanySelect` — DOMContentLoaded syncs office location

#### `hr/employees/edit.blade.php` — Employee Edit
- **Sections F/G/H:** Card-based layout with inline edit/delete for existing records
- **Education:** Existing entries have Edit/Close toggle + inline fields. New entries via `#empAddEduBtn`
- **Spouse:** Existing entries with `empToggleSpouseEdit()`. New entries via `#empAddSpouseBtn`
- **Emergency:** Fixed 2-slot form (no add/remove)
- **Delete tracking:** `edu_delete_ids`, `del_spouse_ids` hidden inputs (comma-separated IDs)
- **Certificate management:** Keep/remove existing + add new (max 5 per entry) via DataTransfer
- **Company select:** `#empCompanySelect` — DOMContentLoaded syncs office location

#### `hr/employees/index.blade.php` — Employee Listing
- Filters, search, pagination
- Minimal JS (form submission)

#### `hr/offboarding/index.blade.php` — Offboarding List
- Overview widget included
- Filter/search forms

#### `hr/payroll/` — Payroll pages
- Upload, calculation, payslip generation
- File upload handlers

#### `hr/leave/` — Leave management
- Approval workflows
- Modal-based interactions

#### `hr/claims/` — Expense claims
- Receipt upload, approval workflow

### IT Module

#### `it/onboarding.blade.php` — IT Onboarding View
- Overview widget included (read-only listing)
- PIC assignment modals
- Minimal JS

#### `it/offboarding.blade.php` — IT Offboarding View
- Overview widget included (read-only listing)
- PIC assignment

#### `it/assets/page.blade.php` — Asset Inventory
- **Complex JS:** Search, filter, photo upload, condition/status sync
- **File uploads:** Async FileReader for photo preview
- **Inline handlers:** `onchange` on filter dropdowns (legacy)
- **addEventListener:** Used for search, file input, photo management

#### `it/assets/edit.blade.php` — Asset Edit
- Similar patterns to page.blade.php
- **Section E condition dropdown** driven by `AssetInventory::CONDITIONS` (adds **Returned**). `syncStatusFromCondition()` toggles the maintenance + decommission-reason wraps and drives the read-only status field; reason wrap shows for both `not_good` and `returned` (required only for `not_good`).
- **Section C vendor `<select>`** — see the shared vendor-picker note below.

#### Vendor picker + auto-fill (`it/assets/page.blade.php` Add modal AND `it/assets/edit.blade.php`)
- **TWO `<select name="vendor_id">` per form**, one inside `#companyFields` and one inside `#rentalFields`, offering different vendor sets (purchase suppliers vs rental vendors). Only the visible panel's may submit, so `toggleOwnership()` `disabled`-gates them — the same device the paired `invoice_documents[]` inputs already use. Miss it and the browser posts two values for one field. Initial `disabled` state is rendered server-side from `old('ownership_type')` / `$ownershipType`, not left to JS on load.
- **Auto-fill (`.js-vendor-picker`)** reads `data-pic/-email/-phone/-tel/-reg/-sst/-address` off the selected `<option>` — no AJAX, so the page can never display details for a vendor since deactivated. Writes the summary via `textContent` (never `innerHTML`) into the `data-detail` target, and fills two inputs on the rental panel: `data-pic-field` ← the vendor's PIC **name**, `data-contact` ← their **phone** (falling back to PIC email, then the vendor's general line).
- **`fill(select, picked)` — the second argument is the whole safety story.** A `change` passes `picked = true` and **overwrites** both fields: choosing a different vendor makes the previous vendor's PIC and number stale, and leaving them would attribute the wrong person to the new vendor. The initial reflect on load passes `false` and fills only what is empty, so opening an asset (or an `old()` replay after a failed submit) never clobbers stored values. Selecting the blank "not registered" option returns early and touches nothing — the operator is about to type an unregistered vendor's details there.
- Free-text `rental_vendor` / `purchase_vendor` inputs remain for unregistered vendors. **They are NOT symmetric any more:** `purchase_vendor` is still server-synced to the picked supplier's company name, but `rental_vendor` holds the vendor's **PIC** (see the Asset ↔ vendor notes in CLAUDE.md) and the vendor filters resolve the company through `vendor_id` instead.

#### `vendors/*` — Vendor Management (directory, form, profile)
- Styling: `partials/vendor-ui-style.blade.php` (`@once`, `vnd-` prefixed) layered on `partials/decommission-ui-style` (`ewx-`) + `partials/dashboard-widgets-style`.
- `vendors/form.blade.php` — one nonce'd IIFE showing/hiding the `.js-ewaste-only` primary toggle off `#vtype_ewaste`; `addEventListener` only.
- `vendors/show.blade.php` — Bootstrap tabs via `data-bs-toggle="tab"` (declarative attributes, not handlers). The open tab comes from `?tab=`, which every contract/billing redirect carries.
- **Modals are rendered OUTSIDE the tab panes and tables** — a `<div>` inside `<tbody>` is invalid markup the browser hoists out, which silently detaches the form from its fields.
- **Multi-modal `old()` pattern:** one Add modal + one Edit modal per row, and `old()` is global. Each form carries a hidden `_form` naming itself; only the form matching `old('_form')` replays `old()`, and a nonce'd block re-opens exactly that modal through `bootstrap.Modal.getOrCreateInstance`. Without this a rejected Add would repopulate every Edit form on the page.
- Delete/re-scan use the shared `form.js-confirm` + `data-confirm*` convention. These forms are rendered server-side and present at load, which matters: the confirm binder is `querySelectorAll` at load time, **not** delegation.

#### `it/assets/page.blade.php` — Decommissioning tab (Asset Decommissioning module)
- **Batch selection:** checkboxes (`.js-batch-check`, only on `vendor_return` rows that are not already on a return form) enable the **Create Collection Batch** button and, on modal `show.bs.modal`, inject the checked ids as hidden `dispose_ids[]` inputs into `#batchIdsContainer`. All in the page's existing nonce'd `@push('scripts')` block — no inline handlers. **Keep the ids `#createBatchBtn` / `#batchSelCount` / `.js-batch-check`**: the button ships `disabled` and only the IIFE enables it, the tab's CSS keys on the class, and `ItDecommissionAccessTest` asserts on `id="createBatchBtn"` (never the phrase "Create Collection Batch", which also appears in a JS comment served to every role).
- **The modal previews the SPLIT before submit** (added 2026-08-10): each checkbox carries `data-vendor` / `data-company` / `data-rental`, and the IIFE groups them into "N forms will be created", one card per (vendor, company rented to), plus a warning list of assets that cannot become a form. It mirrors `RentalAssetAcknowledgement::planReturns()` — the server still decides — and disables `#batchSubmitBtn` when nothing is resolvable. Every dynamic value goes in through `textContent` via a small `el()` helper; the only `innerHTML` clears a container.
- **Run e-waste sweep now / Cancel cycle:** plain POST forms guarded by a CSP-safe `form.js-confirm` (`data-confirm`) submit listener (replaces inline `onsubmit="confirm()"`).
- **Create Collection Batch modal** posts to `decommission.returns.generate` (it raises return AARFs, not a batch); opened declaratively via `data-bs-toggle`/`data-bs-target`.

#### `it/decommission/show.blade.php` — E-waste cycle detail
- E-waste only since 2026-08-10 (the vendor-return block, its ack-link copy control and the nonce'd script that drove it were removed with the flow). Quotation/receipt upload forms are `enctype="multipart/form-data"`; `form.js-confirm` submit-confirm pattern via the shared `partials/confirm-modal`. **No `@push('scripts')` block remains on this page** — don't reintroduce one for the copy button, there is no link to copy.

#### `vendors/aarf/show.blade.php` — the AARF (both directions)
- **ONE `<form id="aarfAckForm">`, declared empty near the top**; every control attaches by `form="aarfAckForm"`. A receipt's two signatories interleave, so a wrapping form would have to nest — invalid HTML browsers silently drop. The receipt's second button differs only by `formaction` and carries **`formnovalidate`** (load-bearing: the receipt's own `required` fields are not the vendor rep's responsibility).
- **A RETURN renders one button and no vendor-rep block** — the collector and our processor post together in a single submit. `$isReturn` drives every direction-specific string; keep `vendors/aarf/pdf.blade.php` in step, it carries the same split.

#### `accounting/fixed-assets/index.blade.php` — Finance: pending quotations (status "Disposed")
- The standalone `finance/ewaste-pending.blade.php` is gone; these controls render inline on the Assets tab when `?status=disposed`. Approve = `form.js-confirm` POST; Reject = per-batch Bootstrap modal (reason required), opened via `data-bs-toggle`. Nonce'd confirm listener.
- **The status filter must stay bound via `addEventListener`** (`#assetStatusSelect` / `#assetStatusFilter`): it once carried `onchange="this.form.submit()"`, which the CSP blocks outright, so choosing "Disposed" silently did nothing and read as a permissions problem.

### User Module

#### `user/profile.blade.php` — Self-Service Profile
- **Most complex user-facing form** (~1400 lines)
- **NRIC files:** Add/remove with DataTransfer API (`profileNricNewFiles[]`)
- **Education:** Add/edit/remove with inline edit toggle
- **Spouse:** Add/edit/remove cards
- **Inline handlers:** 15+ (legacy, CSP-vulnerable)
- **DOMContentLoaded:** Bank toggle, spouse toggle

#### `user/account.blade.php` — Account Settings
- Profile photo upload
- Password change

#### `user/claims/index.blade.php` — Employee Claims
- Fetch-based AJAX for claim operations

#### `user/leave/index.blade.php` — Leave Application
- Leave request form
- Calendar view

### Superadmin Module

#### `superadmin/role-management.blade.php` — Roles & Permissions
- Select2 for role assignment
- Dynamic permission checkboxes

#### `superadmin/system-overview.blade.php` — System Dashboard
- Fetch-based metrics loading

### Accounting Module

#### `accounting/receivables/invoice-form.blade.php` — Sales Invoice
- **CSP-compliant:** Uses addEventListener + event delegation
- Dynamic line items with real-time calculations
- Tax calculation via `data-rate` attributes

#### `accounting/journal-entries/form.blade.php` — Journal Entries
- **CSP-compliant:** createElement + event delegation
- Debit/credit balance validation

#### `accounting/dashboard.blade.php` — Accounting Dashboard
- Chart.js bar chart
- `@json()` data injection

#### `accounting/ai/chatbot.blade.php` — AI Assistant
- Fetch-based chat interface

### Partials

#### `partials/onboarding-overview-widget.blade.php`
- YTD cards with company filter
- `@push('scripts')` for filter JS

#### `partials/offboarding-overview-widget.blade.php`
- Same pattern as onboarding widget

#### `partials/dashboard-widgets-style.blade.php`
- CSS only, no JS

#### `partials/leave-modal.blade.php`
- Bootstrap modal for leave requests

### Auth

#### `auth/set-password.blade.php`
- Password strength checker (inline handlers — legacy)
- Real-time validation feedback

---

## CSP Migration Status

| Status | Files |
|--------|-------|
| **Compliant** | accounting/invoice-form, journal-entries/form, bill-form, budgets/form, banking/reconciliation |
| **Partially fixed** | hr/onboarding/page (Add to List buttons fixed), hr/employees/edit (Add buttons fixed) |
| **Needs migration** | user/profile, auth/set-password, it/assets/page, accounting/dashboard, hr/onboarding/edit |

**Priority for migration:**
1. user/profile.blade.php (15+ inline handlers, most user-facing)
2. hr/onboarding/edit.blade.php (HR daily use)
3. it/assets/page.blade.php (IT daily use)
4. auth/set-password.blade.php (affects all new users)
5. accounting/dashboard.blade.php (single handler, low risk)

---

## Testing Checklist (Manual)

When modifying any page with JS interactions, verify:

- [ ] "Add to List" buttons respond (education, spouse, emergency contacts)
- [ ] Remove/delete buttons on dynamically added entries work
- [ ] Form submits with correct data (check hidden inputs in browser DevTools)
- [ ] File uploads attach correctly (DataTransfer API)
- [ ] Conditional toggles work (marital status -> spouse section)
- [ ] Company selection auto-fills office location
- [ ] Modal re-opens on validation errors
- [ ] No console errors (F12 -> Console tab)
- [ ] CSP violations check (F12 -> Console, look for "Refused to execute inline event handler")
