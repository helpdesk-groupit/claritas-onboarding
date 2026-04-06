# Threat Model — Employee Portal (HRM SaaS)

**System**: Claritas Employee Portal
**Version**: 1.0 (April 2026)
**Classification**: Internal — Confidential
**Last Reviewed**: 2026-04-06

---

## 1. System Overview

The Employee Portal is a multi-role HR management system handling:
- **Employee lifecycle** (onboarding → active → offboarding)
- **IT asset management** (inventory, provisioning, assignment, disposal)
- **Leave management, payroll, attendance tracking, expense claims**
- **AI-powered accounting module**
- **C-suite reporting and analytics**

### 1.1 Architecture

```
Internet → Apache/Nginx (TLS) → PHP-FPM → Laravel Application → MySQL/MariaDB
                                    ├── Redis (cache/sessions)
                                    ├── File Storage (local disk)
                                    └── SMTP (outbound email)
```

### 1.2 Data Classification

| Category | Sensitivity | Examples |
|---|---|---|
| **Restricted** | Highest | NRIC copies, passport scans, salary data, bank accounts |
| **Confidential** | High | Employee personal details, medical info, contracts, payroll |
| **Internal** | Medium | Work schedules, IT assets, leave records, announcements |
| **Public** | Low | Company name, office locations |

---

## 2. Trust Boundaries

```
┌─────────────────────────────────────────────────────────────┐
│  EXTERNAL (Untrusted)                                        │
│  ┌──────────┐  ┌──────────┐  ┌──────────┐                  │
│  │ Browser  │  │ Email    │  │ VPN User │                   │
│  │ (Public) │  │ Client   │  │ (Remote) │                   │
│  └────┬─────┘  └────┬─────┘  └────┬─────┘                  │
├───────┼──────────────┼─────────────┼────────────────────────┤
│  TB1: TLS Termination (HTTPS boundary)                       │
├───────┼──────────────┼─────────────┼────────────────────────┤
│  ┌────▼──────────────▼─────────────▼─────┐                  │
│  │  Web Server (Apache/PHP-FPM)          │                  │
│  │  ┌───────────────────────────────┐    │                  │
│  │  │ TB2: Authentication Gate       │    │                  │
│  │  │  (Login + Session + CSRF)     │    │                  │
│  │  └───────────┬───────────────────┘    │                  │
│  │              │                         │                  │
│  │  ┌───────────▼───────────────────┐    │                  │
│  │  │ TB3: Authorization Layer       │    │                  │
│  │  │  (Role-based middleware)      │    │                  │
│  │  │  HR → IT → Finance → Admin   │    │                  │
│  │  └───────────┬───────────────────┘    │                  │
│  │              │                         │                  │
│  │  ┌───────────▼───────────────────┐    │                  │
│  │  │ TB4: Application Logic         │    │                  │
│  │  │  (Controllers + Services)     │    │                  │
│  │  └───────────┬───────────────────┘    │                  │
│  └──────────────┼────────────────────────┘                  │
├─────────────────┼────────────────────────────────────────────┤
│  TB5: Data Layer Boundary                                     │
│  ┌──────────────▼──────┐  ┌──────────────┐  ┌────────────┐  │
│  │  MySQL/MariaDB      │  │ File Storage  │  │ SMTP       │  │
│  │  (encrypted at rest)│  │ (local disk)  │  │ (outbound) │  │
│  └─────────────────────┘  └──────────────┘  └────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. STRIDE Threat Analysis

### 3.1 Spoofing (Identity)

| # | Threat | Component | Likelihood | Impact | Mitigation | Status |
|---|---|---|---|---|---|---|
| S1 | Credential stuffing / brute force | Login endpoint | High | High | Account lockout after 5 failures; rate limiting (30/min); timing-attack-safe responses | ✅ Implemented |
| S2 | Session hijacking | Session management | Medium | Critical | HttpOnly + Secure + SameSite cookies; single-session enforcement; session regeneration on login | ✅ Implemented |
| S3 | Phishing for credentials | Email links | Medium | High | HSTS enforcement; branded email templates; no credentials in URLs | ✅ Implemented |
| S4 | Password reset abuse | Password reset flow | Medium | High | Generic responses ("if account exists..."); 60-min token expiry; single-use tokens | ✅ Implemented |
| S5 | Token-based access bypass | AARF acknowledgement links | Low | Medium | Unique random tokens; HMAC validation; rate limited (10/min) | ✅ Implemented |

### 3.2 Tampering (Data Integrity)

| # | Threat | Component | Likelihood | Impact | Mitigation | Status |
|---|---|---|---|---|---|---|
| T1 | SQL injection | Database queries | Medium | Critical | Eloquent ORM (parameterized queries); no raw SQL with user input | ✅ Implemented |
| T2 | CSRF attacks | State-changing forms | Medium | High | Laravel CSRF tokens on all POST/PUT/DELETE; SameSite cookies | ✅ Implemented |
| T3 | Log tampering | Audit trail | Low | High | HMAC-chained integrity log; sequence verification | ✅ Implemented |
| T4 | File content manipulation | File uploads | Medium | High | Magic-byte validation; image reprocessing; upload size limits | ✅ Implemented |
| T5 | Backup tampering | Backup files | Low | Critical | AES-256-CBC encryption + HMAC integrity; SHA-256 manifests | ✅ Implemented |
| T6 | Mass assignment | Eloquent models | Medium | High | `$fillable` whitelists on all models | ✅ Implemented |

### 3.3 Repudiation (Deniability)

| # | Threat | Component | Likelihood | Impact | Mitigation | Status |
|---|---|---|---|---|---|---|
| R1 | Deny performing sensitive action | Admin operations | Medium | Medium | SecurityAuditLog records all auth events; HMAC chain integrity | ✅ Implemented |
| R2 | Deny editing employee records | Employee/Onboarding edits | Medium | High | EmployeeEditLog + OnboardingEditLog with user context | ✅ Implemented |
| R3 | Deny consent/acknowledgement | AARF/consent flows | Medium | High | Token-based acknowledgement with timestamp + IP logging | ✅ Implemented |

### 3.4 Information Disclosure

| # | Threat | Component | Likelihood | Impact | Mitigation | Status |
|---|---|---|---|---|---|---|
| I1 | NRIC/passport data exposure | File storage | Medium | Critical | Private disk (non-web-accessible); role-based SecureFileController; rate limiting | ✅ Implemented |
| I2 | Error message information leak | Exception handling | Medium | Medium | Generic error pages in production; APP_DEBUG=false | ✅ Implemented |
| I3 | Server/technology fingerprinting | HTTP headers | Low | Low | X-Powered-By and Server headers removed; CSP set | ✅ Implemented |
| I4 | Image EXIF data leaking PII | Uploaded images (GPS, camera) | Medium | Medium | ImageSanitizer strips all EXIF/metadata on upload | ✅ Implemented |
| I5 | Salary/payroll data exposure | Payroll module | Low | Critical | Role-based access (HR Manager + Superadmin only); no caching | ✅ Implemented |
| I6 | Session data in URL | Authentication | Low | High | Session tokens in cookies only; no URL parameters | ✅ Implemented |
| I7 | Backup data exposure | Backup files | Low | Critical | AES-256-CBC encryption; 0600 permissions; dedicated encryption key | ✅ Implemented |

### 3.5 Denial of Service

| # | Threat | Component | Likelihood | Impact | Mitigation | Status |
|---|---|---|---|---|---|---|
| D1 | Login bruteforce flooding | Login endpoint | High | Medium | Rate limiting (30/min per IP); auto-lockout | ✅ Implemented |
| D2 | File upload storage exhaustion | Upload endpoints | Medium | Medium | Rate limiting (10/min); max file size (10MB); upload count limits | ✅ Implemented |
| D3 | Rapid-fire automated scanning | All endpoints | Medium | Medium | ThreatDetector rate anomaly check (60 req/min); automated alerts | ✅ Implemented |
| D4 | Database connection exhaustion | Application layer | Low | High | Connection pooling; query optimization | ⚠️ Partial |

### 3.6 Elevation of Privilege

| # | Threat | Component | Likelihood | Impact | Mitigation | Status |
|---|---|---|---|---|---|---|
| E1 | Horizontal privilege escalation | Employee data access | Medium | High | Resource ownership verification in controllers; SecureFileController checks | ✅ Implemented |
| E2 | Vertical privilege escalation | Role-based access | Medium | Critical | Middleware role gates; capability methods (canEdit*, canView*); server-side enforcement | ✅ Implemented |
| E3 | Role self-assignment | Role management | Low | Critical | Only Superadmin can assign roles; validation in EmployeeController | ✅ Implemented |
| E4 | Multiple auth failure privilege escal. | Repeated 403s | Medium | High | ThreatDetector detects 3+ unauthorized attempts in 10 min; alerts IT+Superadmin | ✅ Implemented |

---

## 4. Attack Surface Analysis

### 4.1 External Attack Surface

| Entry Point | Protocol | Auth Required | Rate Limited | Notes |
|---|---|---|---|---|
| Login page | HTTPS | No | 30/min/IP | Account lockout after 5 failures |
| Password reset | HTTPS | No | 5/min/IP | Generic response messages |
| AARF acknowledgement | HTTPS | Token-based | 10/min | CSRF exempted (email link access) |
| Registration (invite) | HTTPS | Token-based | Yes | OnboardingInvite token required |

### 4.2 Authenticated Attack Surface

| Entry Point | Min Role | Controls |
|---|---|---|
| Employee CRUD | hr_manager | Capability checks + CSRF + audit logging |
| File upload/download | varies | Magic-byte + MIME validation + image sanitization + role-based |
| Payroll management | hr_manager | Capability checks + encrypted transport |
| Role assignment | superadmin | Strict validation + audit trail |
| Accounting module | finance_manager | `canManageAccounting()` + `canApproveTransactions()` |
| Asset management | it_manager / it_executive | Capability checks + CSRF |

### 4.3 Internal Attack Surface

| Component | Threat | Mitigation |
|---|---|---|
| Database credentials | Credential theft | Stored in .env (not in code); file permissions 0600 |
| Backup files | Data theft | AES-256 encrypted; 0600 permissions; separate encryption key |
| Log files | Evidence tampering | HMAC-chain integrity protection |
| Scheduled commands | Unauthorized execution | Artisan commands require CLI access |

---

## 5. Security Controls Matrix

### 5.1 Preventive Controls

| Control | Implementation | Covers |
|---|---|---|
| TLS/HTTPS enforcement | ForceHttps middleware + HSTS header (1 year) | All network communication |
| CSRF protection | Laravel CSRF tokens on all state-changing requests | Form-based attacks |
| Input validation | Server-side validation rules on all endpoints | Injection attacks |
| Magic-byte file validation | `valid_file_content` custom validator | Malicious file upload |
| Image metadata stripping | ImageSanitizer GD reprocessing | EXIF PII leakage + polyglot attacks |
| Role-based access control | Middleware + capability methods on User model | Unauthorized access |
| Encrypted backups | AES-256-CBC + HMAC integrity | Backup data theft |
| Security headers | SecurityHeaders middleware (CSP, HSTS, X-Frame, etc.) | Browser-based attacks |

### 5.2 Detective Controls

| Control | Implementation | Covers |
|---|---|---|
| Security audit logging | SecurityAuditLog model + middleware | All authentication/authorization events |
| HMAC log integrity | LogIntegrity service with chained hashing | Log tampering |
| Threat detection | ThreatDetector service (brute force, privilege escalation, rate anomaly) | Active attacks |
| Backup manifest | SHA-256 hash verification | Backup integrity |
| Log integrity verification | `log:verify-integrity` artisan command (daily scheduled) | Audit trail tampering |

### 5.3 Responsive Controls

| Control | Implementation | Covers |
|---|---|---|
| Account lockout | Auto-deactivation after 5 failed logins | Brute force attacks |
| Real-time alerts | SuspiciousActivityAlert email to IT Manager + Superadmin | Active threats |
| Hourly security digest | SecurityAuditReport command + SecurityAuditMail | Security event review |
| Alert deduplication | 15-minute cache-based dedup | Alert fatigue prevention |

---

## 6. Data Flow Security

### 6.1 Authentication Flow

```
Browser → [HTTPS/TLS] → ForceHttps → SecurityHeaders → Login Controller
    ↓ fail (×5)                                              ↓
ThreatDetector ← SecurityAuditLog ← Account Lockout    Validate credentials
    ↓                                                        ↓ success
Alert IT Manager                                    Session regenerate + login
+ Superadmin                                         Single-session enforcement
```

### 6.2 File Upload Flow

```
Browser → [HTTPS/TLS] → Rate limiter (10/min) → Controller
    ↓
Validation: required|file|max:10240|mimes:jpg,png,pdf|valid_file_content|sanitize_image
    ↓ image?
ImageSanitizer → GD reprocess (strip EXIF, neutralize polyglots)
    ↓
Store to private disk (storage/app/private/) with server-generated filename
    ↓
SecureFileController serves with role-based access check
```

### 6.3 Backup Flow

```
Scheduler (02:00 daily) → backup:run --type=full --encrypt
    ↓
mysqldump → gzip → AES-256-CBC encrypt + HMAC → storage/app/backups/
    ↓
tar codebase → gzip → AES-256-CBC encrypt + HMAC → storage/app/backups/
    ↓
Write SHA-256 manifest → Prune backups older than 30 days
```

---

## 7. Residual Risks & Accepted Trade-offs

| Risk | Severity | Justification | Monitoring |
|---|---|---|---|
| No MFA | Medium | Single-tenant internal system; compensated by single-session + lockout + VPN access | Login monitoring |
| Local file storage (no S3) | Medium | Synology NAS with RAID; compensated by encrypted backups + restricted permissions | Backup verification |
| No WAF | Low | Internal network (VPN-gated); compensated by application-level rate limiting + CSP | Request rate monitoring |
| Single database (no replica) | Medium | NAS RAID storage; compensated by 6-hourly DB snapshots | Backup success monitoring |

---

## 8. Review Schedule

| Activity | Frequency | Owner |
|---|---|---|
| Threat model review | Quarterly | IT Manager |
| Dependency vulnerability scan | Monthly | System Admin |
| Security audit log review | Weekly | IT Manager |
| Backup restore test | Monthly | System Admin |
| Password policy review | Annually | HR Manager + IT Manager |
| Penetration test (external) | Annually | IT Manager |

---

*Document generated: 2026-04-06 | Next review due: 2026-07-06*
