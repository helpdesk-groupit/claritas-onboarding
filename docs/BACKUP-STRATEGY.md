# Backup & Disaster Recovery Strategy

**System**: Claritas Employee Portal
**Version**: 1.0 (April 2026)
**Classification**: Internal — Confidential
**RPO Target**: 6 hours | **RTO Target**: 2 hours

---

## 1. Backup Overview

### 1.1 Backup Types

| Type | Contents | Schedule | Retention | Encrypted |
|---|---|---|---|---|
| **Full backup** | Database + codebase | Daily at 02:00 MYT | 30 days | AES-256-CBC |
| **Database snapshot** | MySQL/MariaDB dump | Every 6 hours | 7 days | AES-256-CBC |
| **Git repository** | Source code + history | Every `git push` | Permanent | SSH transport |

### 1.2 Storage Locations

| Location | Purpose | Redundancy |
|---|---|---|
| `storage/app/backups/` | Local NAS backup storage | NAS RAID (protected) |
| GitHub (`origin`) | Remote code repository | GitHub redundancy |
| NAS bare repo | On-premise code repository | NAS RAID |

---

## 2. Backup Architecture

### 2.1 Automated Backup Flow

```
┌─────────────────────────────────────────────────────────┐
│  Laravel Scheduler (cron)                                │
├─────────────────────────────────────────────────────────┤
│                                                          │
│  02:00 daily → backup:run --type=full --encrypt --keep=30│
│       ├── mysqldump --single-transaction → gzip          │
│       ├── tar codebase (excl vendor/node_modules) → gzip │
│       ├── AES-256-CBC encrypt each file + HMAC           │
│       ├── Write SHA-256 manifest (JSON)                  │
│       └── Prune files > 30 days old                      │
│                                                          │
│  Every 6h → backup:run --type=database --encrypt --keep=7│
│       ├── mysqldump --single-transaction → gzip          │
│       ├── AES-256-CBC encrypt + HMAC                     │
│       └── Write SHA-256 manifest                         │
│                                                          │
│  03:00 daily → log:verify-integrity                      │
│       └── Verify HMAC chain on integrity.log             │
│                                                          │
└─────────────────────────────────────────────────────────┘
```

### 2.2 Backup File Naming Convention

```
db_2026-04-06_020000.sql.gz.enc       # Encrypted database dump
code_2026-04-06_020000.tar.gz.enc     # Encrypted codebase archive
manifest_2026-04-06_020000.json       # Integrity manifest (SHA-256 hashes)
```

---

## 3. Encryption Policy

### 3.1 Encryption Standards

| Aspect | Standard |
|---|---|
| Algorithm | AES-256-CBC |
| Key derivation | Dedicated `BACKUP_ENCRYPTION_KEY` or fall back to `APP_KEY` |
| IV | 16 random bytes (CSPRNG) per file, stored as file prefix |
| Integrity | HMAC-SHA256 appended to each encrypted file |
| File permissions | 0600 (owner read/write only) |

### 3.2 Key Management

| Key | Purpose | Storage | Rotation |
|---|---|---|---|
| `BACKUP_ENCRYPTION_KEY` | Encrypts/decrypts backup files | `.env` file (not in Git) | Quarterly |
| `LOG_INTEGRITY_KEY` | Signs audit log entries (HMAC) | `.env` file (not in Git) | Quarterly |
| `APP_KEY` | Laravel encryption (sessions, cache) | `.env` file (not in Git) | Annual |

### 3.3 Key Rotation Procedure

1. Generate new key: `php artisan key:generate` (for APP_KEY) or `openssl rand -base64 32` (for BACKUP/LOG keys)
2. Update `.env` with new key
3. **Do NOT delete old key** — keep it documented for decrypting older backups
4. Create a new backup immediately after rotation to ensure a backup exists with the new key
5. Document the rotation date and old key hash (not the key itself) in the security log

---

## 4. Backup Verification

### 4.1 Automated Verification

Every backup run generates a `manifest_<timestamp>.json` containing:

```json
{
  "timestamp": "2026-04-06_020000",
  "created_at": "2026-04-06T02:00:00+08:00",
  "hostname": "nas-server",
  "files": [
    {
      "name": "db_2026-04-06_020000.sql.gz.enc",
      "size": 5242880,
      "sha256": "a1b2c3d4e5f6..."
    }
  ]
}
```

### 4.2 Manual Verification

```bash
# Verify log integrity
php artisan log:verify-integrity

# Test backup restore (dry run — validates without restoring)
php artisan backup:restore db_2026-04-06_020000.sql.gz.enc --decrypt --dry-run

# Full restore (with confirmation prompt)
php artisan backup:restore db_2026-04-06_020000.sql.gz.enc --decrypt
```

### 4.3 Monthly Restore Test Procedure

1. Create a test database: `CREATE DATABASE claritas_restore_test;`
2. Temporarily set `DB_DATABASE=claritas_restore_test` in `.env`
3. Run: `php artisan backup:restore <latest-db-backup> --decrypt`
4. Verify data: `php artisan tinker` → check record counts
5. Drop test database: `DROP DATABASE claritas_restore_test;`
6. Restore `.env` to original value
7. Document test result in security log

---

## 5. Disaster Recovery Procedures

### 5.1 Scenario: Database Corruption

**RTO**: 30 minutes

1. Identify the latest clean backup: `ls -lt storage/app/backups/db_*.enc`
2. Restore: `php artisan backup:restore db_<timestamp>.sql.gz.enc --decrypt`
3. Run pending migrations: `php artisan migrate`
4. Verify critical records: employee count, user count, latest transactions
5. Clear application cache: `php artisan config:clear; php artisan cache:clear`

### 5.2 Scenario: Full Server Loss

**RTO**: 2 hours

1. Provision new server (Synology NAS or cloud VM)
2. Install dependencies: PHP 8.2+, MySQL/MariaDB, Apache/Nginx, Git, Composer, Node.js
3. Clone from GitHub: `git clone <repo-url>`
4. Copy `.env` from secure backup / documentation
5. Install PHP deps: `composer install --no-dev`
6. Install frontend: `npm ci && npm run build`
7. Restore database from latest encrypted backup
8. Run migrations: `php artisan migrate`
9. Set permissions: `chmod -R o+rX . && chmod -R o+rwX storage bootstrap/cache`
10. Configure web server virtual host
11. Verify: access the login page, check database connectivity

### 5.3 Scenario: Ransomware / Data Breach

**RTO**: 4 hours

1. **Isolate**: Disconnect affected server from network immediately
2. **Assess**: Determine scope — which systems, data, and users are affected
3. **Preserve evidence**: Do NOT delete logs or files — copy forensic image if possible
4. **Notify**: Alert IT Manager, Superadmin, and management
5. **Restore**: Follow "Full Server Loss" procedure on a clean server
6. **Rotate**: Change ALL credentials (DB password, APP_KEY, BACKUP_ENCRYPTION_KEY, SMTP, API keys)
7. **Force password reset**: Deactivate all user accounts; issue password reset links
8. **Review**: Check audit logs (integrity-protected) for scope of unauthorized access
9. **Report**: Document incident per company policy

---

## 6. Artisan Commands Reference

| Command | Purpose |
|---|---|
| `backup:run --type=full --encrypt` | Full encrypted backup (DB + code) |
| `backup:run --type=database --encrypt` | Database-only encrypted backup |
| `backup:run --type=code` | Codebase-only backup |
| `backup:run --keep=7` | Set retention to 7 days |
| `backup:restore <file> --decrypt` | Restore an encrypted backup |
| `backup:restore <file> --decrypt --dry-run` | Validate without restoring |
| `log:verify-integrity` | Verify HMAC chain on audit logs |

---

## 7. Monitoring & Alerting

| Check | Method | Frequency | Alert To |
|---|---|---|---|
| Backup success | `backup.log` output | After each run | IT Manager (review weekly) |
| Backup file exists | Check `storage/app/backups/` | Daily | IT Manager |
| Log integrity | `log:verify-integrity` output | Daily at 03:00 | IT Manager |
| Disk space | NAS monitoring | Continuous | IT Manager |
| Restore test | Manual procedure | Monthly | System Admin |

---

## 8. Compliance Notes

- **Data Retention**: Backups are retained for 30 days (full) and 7 days (DB snapshots), aligning with Malaysian PDPA requirements for data minimization
- **Encryption**: AES-256-CBC meets international encryption standards (NIST, ISO 27001)
- **Access Control**: Backup files are restricted to 0600 permissions; only server admin can access
- **Audit Trail**: All backup operations are logged; integrity-protected audit log provides non-repudiation

---

*Document generated: 2026-04-06 | Next review due: 2026-07-06*
