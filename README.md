# Database Backup Suite (packs 1–9)

A **modular PHP 8.x application** for secure MySQL backups, encryption, off‑site copy, and notifications.
Version 2025‑06 — now ships with AWS SDK (SES · SNS) and Twilio SDK support.

| Pack | Capability                             | Key Modules                       |
| ---- | -------------------------------------- | --------------------------------- |
| 1    | Multi‑DB selector                      | `config/config.php`, `UI.php`     |
| 2    | AES‑256‑GCM encryption · TOTP · CSRF   | `Security.php`                    |
| 3    | mysqldump fast‑path · table‑skip       | `Db.php`, `Performance.php`       |
| 4    | Off‑site copy (S3 / rclone / FTP‑SFTP) | `Uploader.php`                    |
| 5    | One‑click restore                      | `Db.php`                          |
| 6    | Cron scheduler + daily digest          | `Scheduler.php`                   |
| 7    | Dashboard log viewer                   | `UI.php`                          |
| 8    | Role‑based access                      | `config/config.php`, `Router.php` |
| 9    | Out‑going webhooks (HMAC)              | `Router.php`, `Notifier.php`      |

---

## 1  Folder layout

```
db-backup/
├── composer.json              # aws/aws-sdk‑php, twilio/sdk, phpmailer
├── vendor/                    # created after `composer install`
│
├── public/
│   └── index.php              # 6‑line entry point (Browser & CLI)
│
├── config/
│   └── config.php             # all credentials & settings
│
├── src/                       # PSR‑4 classes (autoloaded)
│   ├── Bootstrap.php          # config loader · session · polyfills
│   ├── Security.php           # CSRF · TOTP · AES‑GCM helpers
│   ├── Utils.php              # curl + SigV4 + log helpers
│   ├── Performance.php        # mysqldump path + skip‑table regex
│   ├── Db.php                 # backup / restore engines
│   ├── Uploader.php           # S3 · rclone · FTP/SFTP
│   ├── Notifier.php           # php_mail · SMTP · AWS SES/SNS · Twilio SMS …
│   ├── Scheduler.php          # cron matcher + digest mail
│   ├── UI.php                 # HTML dashboard renderer
│   └── Router.php             # maps HTTP/CLI actions → modules
│
├── backups/                   # auto‑created per‑DB (keep writable 755)
├── scheduler.log              # created at runtime
├── notifier.log               # created at runtime (debug mode)
└── README.md
```

---

## 2  Prerequisites

| Component    | Minimum                                        |
| ------------ | ---------------------------------------------- |
| **PHP**      | 8.0+ with **libsodium**, **curl**, **openssl** |
| **Composer** | installs AWS SDK, Twilio SDK, PHPMailer        |
| MySQL CLI    | `mysqldump` + `mysql` available in `PATH`      |
| Cron         | job running every minute (`* * * * *`)         |

> **Shared hosting tip:** on cPanel use `ea‑php82` CLI (run `whereis ea-php82`).

---

## 3  Installation

```bash
# 1  Upload or clone the repo (public/ must be inside your doc‑root)
cd /path/to/db-backup

# 2  Install dependencies (AWS SDK, Twilio SDK, PHPMailer)
composer install --no-dev

# 3  Create writable dump folder
mkdir backups && chmod 755 backups
```

### Configure `config/config.php`

1. **Databases** → host, port, name, user, pass.
2. Generate **bcrypt** password hashes for each user:

   ```bash
   php -r "echo password_hash('Secret!', PASSWORD_DEFAULT), PHP_EOL;"
   ```
3. 32‑byte **encryption key**:

   ```bash
   openssl rand -base64 32
   ```
4. Fill creds for any **notification** or **storage** drivers you’ll use.

### Add system cron

```
* * * * * php /full/path/public/index.php cron
```

This single cron entry triggers the internal scheduler once per minute.

---

## 4  Login & Roles

| Role         | Permissions set           |
| ------------ | ------------------------- |
| `admin`      | **All actions**           |
| `maintainer` | Backup · Upload · Restore |
| `viewer`     | View / Download / Logs    |

Login flow → **username → password → (optional) TOTP**.

---

## 5  Daily workflow

| Action          | Browser button | CLI equivalent                                   |
| --------------- | -------------- | ------------------------------------------------ |
| **Run Backup**  | `Run Backup`   | `php public/index.php backup primary`            |
| **Upload Last** | `Upload Last`  | —                                                |
| **Restore**     | `Restore` link | `php public/index.php restore primary dump_…enc` |
| **Logs**        | `Logs`         | —                                                |

Backups land as `backups/<alias>/dump_<alias>_YYYYmmdd_HHMMSS.sql.gz[.enc]`.

---

## 6  Scheduler (pack 6)

* Define cron expressions in `schedule.jobs`.
* The minute‑cron (`… cron`) checks each rule and fires due backups.
* Digest e‑mail picks today’s lines from `scheduler.log` and sends via
  the **success\_drivers** list at `schedule.digest.time` (e.g. `06:00`).

---

## 7  Off‑site copy (pack 4)

| Target              | Config keys        | Notes                                    |
| ------------------- | ------------------ | ---------------------------------------- |
| S3 / Wasabi / MinIO | `storage.s3.*`     | Uses AWS SDK; set `endpoint` if non‑AWS. |
| `rclone` remote     | `storage.rclone.*` | Requires `rclone` configured on host.    |
| FTP / SFTP          | `storage.ftp.*`    | Native PHP FTP or SSH2 extension.        |

Uploads run automatically after each backup; **Upload Last** retries.

---

## 8  Notifications

| Driver key       | Library               | Transport |
| ---------------- | --------------------- | --------- |
| `php_mail`       | none (native)         | `mail()`  |
| `smtp`           | PHPMailer             | SMTP      |
| `ses`            | **AWS SDK**           | HTTPS API |
| `sns`            | **AWS SDK**           | HTTPS API |
| `twilio`         | **Twilio SDK**        | HTTPS API |
| `sendgrid`       | none (curl JSON)      | HTTPS API |
| `mailgun`        | none (curl form‑data) | HTTPS API |
| `slack`          | none (curl JSON)      | Webhook   |
| `africastalking` | none (curl form‑data) | HTTPS API |

Set global `drivers` or separate `success_drivers` / `failure_drivers`.

---

## 9  Webhooks (pack 9)

* POST JSON on `backup`, `restore`, `upload` events.
* Header `X‑Backup‑Sig` = `HMAC‑SHA256(payload, webhook.secret)`.
* Retries ×3 (1 → 2 → 4 seconds).

---

## 10  Troubleshooting

| Symptom                           | What to check                                         |
| --------------------------------- | ----------------------------------------------------- |
| **500 Internal Server Error**     | `php -l` every file; enable `display_errors` locally. |
| `Undefined function str_contains` | Server runs PHP < 8.0 — upgrade CLI & FPM.            |
| `missing sodium function`         | Enable `ext‑sodium` or disable encryption.            |
| Backup ran but not uploaded       | See `notifier.log` / rclone exit code.                |
| Scheduler didn’t fire             | Confirm system cron line and file permissions.        |

---

## 11  Updating

```bash
cd /path/to/db-backup

# Pull new revision
 git pull

# Update PHP deps
 composer install --no-dev

# Quick lint check
 php -l public/index.php
```

Old backups stay in place; config file preserved.

---

© 2025 DB‑Backup Suite — Apache 2.0 License
