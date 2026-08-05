# Deployment

This describes deploying L-SIAMS on a single Linux server inside a school's LAN,
which is what the system is designed for. Nothing here requires internet access
at runtime.

---

## 1. Server preparation

### Packages

```bash
# Debian / Ubuntu
sudo apt update
sudo apt install -y \
    php8.4 php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-zip \
    php8.4-gd php8.4-curl php8.4-xml php8.4-sockets \
    mysql-server nginx

# RHEL / Rocky
sudo dnf install -y \
    php php-fpm php-mysqlnd php-mbstring php-zip php-gd php-json php-sockets \
    mysql-server nginx
```

Confirm every extension the application needs is loaded:

```bash
php -m | grep -E '^(pdo_mysql|openssl|mbstring|zip|gd|json|zlib|sockets)$'
```

All eight must appear. `sockets` is used only by the realtime server; the rest
are used by the web application and will cause a hard failure at boot if absent.

`gmp` is **not** required. The base62 encoder in the key path uses it when
present and falls back to rejection sampling when it is not; both routes produce
the same 59-character keys, and `php bin/console security:audit-keys` exercises
whichever one this host takes.

### MySQL or MariaDB

Both are supported and the schema has been verified on each. One difference is
worth knowing if you ever revisit the foreign keys: MariaDB refuses to build a
generated column over a foreign-key child column that carries a write-back
referential action (`ON UPDATE CASCADE`, `ON DELETE SET NULL`). Three of the
system's uniqueness guarantees are generated columns over such columns, so those
foreign keys use `RESTRICT` on both actions. Neither action was reachable anyway
— the parents are AUTO_INCREMENT surrogate keys that are never updated, and rows
are soft-deleted rather than removed — so nothing is lost, but changing them back
to `CASCADE` would break `migrate` on MariaDB.

### PHP configuration

In `/etc/php/8.4/fpm/php.ini`:

```ini
; Uploads — student photo imports and CSV/XLSX imports.
upload_max_filesize = 8M
post_max_size       = 10M
max_file_uploads    = 20

; A 500-row import with a full transaction needs headroom, but not minutes.
max_execution_time  = 120
memory_limit        = 256M

; The application sets its own cookie parameters; these are the backstop.
session.cookie_httponly = 1
session.cookie_secure   = 1
session.cookie_samesite = Strict
session.use_strict_mode = 1

; Do not disclose the PHP version in response headers.
expose_php = Off

; Errors go to the log, never to the browser.
display_errors     = Off
log_errors         = On
error_log          = /var/log/php/lsiams-error.log
```

Restart PHP-FPM afterwards: `sudo systemctl restart php8.4-fpm`.

---

## 2. Files and permissions

```bash
sudo git clone <repository-url> /var/www/lsiams
cd /var/www/lsiams

sudo chown -R www-data:www-data /var/www/lsiams

# The application only needs to write to these three places.
sudo chmod -R 750 /var/www/lsiams
sudo chmod -R 770 storage public/uploads database/backups

# .env holds the database password and the master keys.
sudo chmod 640 .env
sudo chown root:www-data .env
```

Only `public/` is served. `app/`, `config/`, `database/`, `storage/` and `.env`
must never be reachable over HTTP — the web-server configurations below place the
document root at `public/` precisely so they cannot be.

---

## 3. Database

### Create the schema and the application user

```sql
CREATE DATABASE lsiams_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

CREATE USER 'lsiams_app'@'localhost'
  IDENTIFIED BY '<a long random password>';

GRANT SELECT, INSERT, UPDATE, DELETE
  ON lsiams_db.* TO 'lsiams_app'@'localhost';

FLUSH PRIVILEGES;
```

### Then take the write privileges away from the immutable tables

This is the third layer of the immutability guarantee, and the only one an
attacker holding the application's own credentials cannot get around. Apply it
**after** running the migrations, because migrations need DDL rights that this
user must not keep:

```sql
-- These three are append-only. The application reads them and adds to them;
-- it has no legitimate reason to change or remove a row, so it cannot.
REVOKE UPDATE, DELETE ON lsiams_db.audit_logs    FROM 'lsiams_app'@'localhost';
REVOKE UPDATE, DELETE ON lsiams_db.security_logs FROM 'lsiams_app'@'localhost';
REVOKE UPDATE, DELETE ON lsiams_db.login_history FROM 'lsiams_app'@'localhost';

-- Attendance rows are never deleted. They *are* updated — see below.
REVOKE DELETE ON lsiams_db.attendance_records FROM 'lsiams_app'@'localhost';
REVOKE DELETE ON lsiams_db.rfid_logs          FROM 'lsiams_app'@'localhost';
REVOKE DELETE ON lsiams_db.fingerprint_logs   FROM 'lsiams_app'@'localhost';
REVOKE DELETE ON lsiams_db.attendance_modifications FROM 'lsiams_app'@'localhost';

FLUSH PRIVILEGES;
```

> **Do not revoke UPDATE on `attendance_records`.** Unlike the log tables, an
> attendance row is legitimately written twice: the tap-in creates it, and the
> tap-out writes `time_out`, `duration_minutes`, `departure_status` and
> `final_status` onto the same row. Session close does the same for automatic
> time-outs, and an administrator correction may amend times and statuses.
> Revoking UPDATE here would break tap-out entirely.
>
> What protects the row instead is the `trg_attendance_immutable_identity`
> trigger, which rejects any UPDATE that changes the student, session, section,
> grade level, subject, teacher, classroom, card UID, request id or creation
> time. The mutable surface is deliberately narrow: times and statuses, nothing
> else. A row can be corrected; it can never become somebody else's.
>
> Every correction is additionally recorded in `attendance_modifications` with
> the administrator, the field, the old and new values and a mandatory reason —
> a second trigger rejects a blank one.

Run the migrations as a separate, more privileged user:

```sql
CREATE USER 'lsiams_migrate'@'localhost' IDENTIFIED BY '<another long password>';
GRANT ALL PRIVILEGES ON lsiams_db.* TO 'lsiams_migrate'@'localhost';
GRANT SUPER ON *.* TO 'lsiams_migrate'@'localhost';   -- needed to create triggers
FLUSH PRIVILEGES;
```

Put `lsiams_migrate` in `.env` while installing, then switch `.env` back to
`lsiams_app` before the system goes live, and drop or lock the migrate user until
the next upgrade.

### MySQL tuning

In `/etc/mysql/mysql.conf.d/lsiams.cnf`:

```ini
[mysqld]
# --- correctness -----------------------------------------------------------
# The application relies on READ COMMITTED plus explicit row locks. REPEATABLE
# READ would hold gap locks that turn concurrent taps on adjacent students into
# avoidable lock waits.
transaction_isolation = READ-COMMITTED

# Every write must survive a power cut. A school's mains supply is not a data
# centre's, and losing the last few taps is not acceptable.
innodb_flush_log_at_trx_commit = 1
sync_binlog                    = 1

# Reject silently-truncating writes rather than storing something wrong.
sql_mode = STRICT_TRANS_TABLES,NO_ZERO_IN_DATE,NO_ZERO_DATE,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION

# --- sizing (assumes 4 GB RAM; scale to ~60% of RAM on a larger box) --------
innodb_buffer_pool_size = 2G
innodb_log_file_size    = 512M
innodb_flush_method     = O_DIRECT

# --- concurrency -----------------------------------------------------------
# Taps hold row locks for milliseconds. A wait longer than a few seconds means
# something is genuinely stuck, and failing fast is better than a pile-up.
innodb_lock_wait_timeout = 5
max_connections          = 200

# --- character set ---------------------------------------------------------
character_set_server = utf8mb4
collation_server     = utf8mb4_unicode_ci

# --- logging ---------------------------------------------------------------
slow_query_log      = 1
slow_query_log_file = /var/log/mysql/lsiams-slow.log
long_query_time     = 1
```

`innodb_lock_wait_timeout = 5` matters. The application retries deadlocks
(1213) and lock-wait timeouts (1205) with 50 / 150 / 400 ms backoff; a long
server-side timeout would make a stuck transaction hold up a queue of taps for
50 seconds before the retry logic ever sees it.

---

## 4. Web server

### nginx

`/etc/nginx/sites-available/lsiams`:

```nginx
server {
    listen 80;
    server_name lsiams.school.local 192.168.1.10;

    # Everything is HTTPS. The device API signs requests, but the browser
    # session cookie must never travel in the clear.
    return 301 https://$host$request_uri;
}

server {
    listen 443 ssl http2;
    server_name lsiams.school.local 192.168.1.10;

    root /var/www/lsiams/public;
    index index.php;

    ssl_certificate     /etc/ssl/lsiams/server.crt;
    ssl_certificate_key /etc/ssl/lsiams/server.key;
    ssl_protocols       TLSv1.2 TLSv1.3;
    ssl_ciphers         HIGH:!aNULL:!MD5;
    ssl_prefer_server_ciphers on;
    ssl_session_cache   shared:SSL:10m;

    client_max_body_size 10M;

    # Do not advertise the server version.
    server_tokens off;

    # The application sets its own security headers; these cover static files
    # too, which never reach PHP.
    add_header X-Content-Type-Options nosniff always;
    add_header X-Frame-Options DENY always;
    add_header Referrer-Policy strict-origin-when-cross-origin always;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_read_timeout 120;
    }

    # Uploads are user-supplied bytes. Serving them is fine; executing them is
    # not, and neither is letting a browser sniff one into a script.
    location ^~ /uploads/ {
        location ~ \.php$ { deny all; }
        add_header X-Content-Type-Options nosniff always;
        add_header Content-Disposition "inline" always;
    }

    # Nothing outside public/ is reachable anyway, but say so explicitly.
    location ~ /\.(?!well-known) { deny all; }
    location ~ ^/(app|config|database|storage|routes|bin|tests|firmware|realtime)/ { deny all; }

    access_log /var/log/nginx/lsiams-access.log;
    error_log  /var/log/nginx/lsiams-error.log warn;
}
```

Enable it:

```bash
sudo ln -s /etc/nginx/sites-available/lsiams /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
```

### Apache

`/etc/apache2/sites-available/lsiams.conf`:

```apache
<VirtualHost *:80>
    ServerName lsiams.school.local
    Redirect permanent / https://lsiams.school.local/
</VirtualHost>

<VirtualHost *:443>
    ServerName lsiams.school.local
    DocumentRoot /var/www/lsiams/public

    SSLEngine on
    SSLCertificateFile    /etc/ssl/lsiams/server.crt
    SSLCertificateKeyFile /etc/ssl/lsiams/server.key
    SSLProtocol -all +TLSv1.2 +TLSv1.3

    <Directory /var/www/lsiams/public>
        Options -Indexes -MultiViews +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Refuse to serve anything above the document root even if a symlink or a
    # misconfiguration ever exposes it.
    <Directory /var/www/lsiams>
        Require all denied
    </Directory>

    ServerTokens Prod
    ServerSignature Off

    ErrorLog  ${APACHE_LOG_DIR}/lsiams-error.log
    CustomLog ${APACHE_LOG_DIR}/lsiams-access.log combined
</VirtualHost>
```

```bash
sudo a2enmod rewrite ssl headers
sudo a2ensite lsiams
sudo apachectl configtest && sudo systemctl reload apache2
```

The repository ships `public/.htaccess` (front-controller rewriting and security
headers) and `public/uploads/.htaccess` (`php_flag engine off`), both of which
Apache picks up automatically.

---

## 5. TLS

The devices and browsers are all on the school LAN, so a public CA is neither
available nor necessary. Use an internal CA and install its root certificate on
every terminal and every administrator machine.

```bash
sudo mkdir -p /etc/ssl/lsiams && cd /etc/ssl/lsiams

# Root CA — keep ca.key offline, on removable media, not on this server.
sudo openssl req -x509 -newkey rsa:4096 -sha256 -days 3650 -nodes \
    -keyout ca.key -out ca.crt \
    -subj "/CN=School Internal CA/O=<School Name>"

# Server certificate. The SANs must list every name and address that will be
# used to reach the server, including the bare IP the terminals are configured
# with — a terminal validating a certificate cannot fall back to a name.
sudo openssl req -newkey rsa:2048 -sha256 -nodes \
    -keyout server.key -out server.csr \
    -subj "/CN=lsiams.school.local/O=<School Name>"

cat > san.cnf <<'EOF'
subjectAltName = DNS:lsiams.school.local, DNS:lsiams, IP:192.168.1.10
keyUsage = critical, digitalSignature, keyEncipherment
extendedKeyUsage = serverAuth
EOF

sudo openssl x509 -req -in server.csr -CA ca.crt -CAkey ca.key -CAcreateserial \
    -out server.crt -days 825 -sha256 -extfile san.cnf

sudo chmod 600 server.key ca.key
sudo chown root:root /etc/ssl/lsiams/*
```

Copy `ca.crt` into `firmware/L_SIAMS_Terminal/` and reference it from
`config.h` so the terminals validate the server rather than trusting whatever
answers. A terminal configured to skip validation would accept any device on the
LAN claiming to be the server.

---

## 6. Services

Two long-running processes: the maintenance worker and the realtime server.

Both unit files are in the repository — `docs/deploy/lsiams-worker.service` and
`docs/deploy/lsiams-realtime.service` — so they can be copied straight into
place rather than retyped. They are reproduced here for reference.

`/etc/systemd/system/lsiams-worker.service`:

```ini
[Unit]
Description=L-SIAMS maintenance worker
After=network.target mysql.service
Requires=mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/lsiams
ExecStart=/usr/bin/php /var/www/lsiams/bin/console worker --interval=60
Restart=always
RestartSec=10

# The worker touches the database and the storage directory, nothing else.
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/www/lsiams/storage /var/www/lsiams/database/backups

StandardOutput=append:/var/log/lsiams/worker.log
StandardError=append:/var/log/lsiams/worker.log

[Install]
WantedBy=multi-user.target
```

`/etc/systemd/system/lsiams-realtime.service`:

```ini
[Unit]
Description=L-SIAMS realtime WebSocket server
After=network.target mysql.service
Requires=mysql.service

[Service]
Type=simple
User=www-data
Group=www-data
WorkingDirectory=/var/www/lsiams
ExecStart=/usr/bin/php /var/www/lsiams/realtime/server.php
Restart=always
RestartSec=5

NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
ReadWritePaths=/var/www/lsiams/storage

# Binding 8443 needs no privilege; reading the TLS key does.
SupplementaryGroups=ssl-cert

StandardOutput=append:/var/log/lsiams/realtime.log
StandardError=append:/var/log/lsiams/realtime.log

[Install]
WantedBy=multi-user.target
```

```bash
sudo mkdir -p /var/log/lsiams && sudo chown www-data:www-data /var/log/lsiams
sudo systemctl daemon-reload
sudo systemctl enable --now lsiams-worker lsiams-realtime
sudo systemctl status lsiams-worker lsiams-realtime
```

If the realtime server cannot start, the web application still works — the
front-end falls back to SSE and then to polling on its own. If the **worker**
is not running, sessions do not auto-close, stale devices are never marked
offline, and the retention sweeps never run. Monitor it.

### What the worker does, each pass

| Task | Frequency |
|---|---|
| Close sessions past their expiry window | every pass |
| Warn sessions closing within 5 minutes | every pass |
| Mark devices offline after missed heartbeats | every pass |
| Expire idle and absolute web sessions | every pass |
| Auto-revoke rotated API keys past their grace window | every pass |
| Retention sweeps (heartbeats, notifications, realtime events, processed requests, nonces) | hourly |

---

## 7. Log rotation

`/etc/logrotate.d/lsiams`:

```
/var/log/lsiams/*.log /var/www/lsiams/storage/logs/*.log {
    daily
    rotate 30
    compress
    delaycompress
    missingok
    notifempty
    create 0640 www-data www-data
    sharedscripts
}
```

Application logs are for operations. The **audit log, security log and login
history live in the database, not in files**, are never rotated, and are never
deleted — they are the record of who did what.

---

## 8. Backups

The application takes gzip-compressed, AES-256-GCM-encrypted backups. Encryption
uses `APP_KEY`, so **a backup is worthless without `.env`** — store a copy of
`APP_KEY` somewhere separate from the backups themselves, or a restore after a
disk failure will be impossible.

Nightly, via cron for `www-data`:

```cron
15 1 * * * cd /var/www/lsiams && /usr/bin/php bin/console backup >> /var/log/lsiams/backup.log 2>&1
```

Then copy the encrypted files off the machine — a backup on the same disk as the
database is not a backup:

```cron
45 1 * * * rsync -a --delete /var/www/lsiams/database/backups/ /mnt/nas/lsiams-backups/
```

Verify restores on a scratch database at least once a term. An unverified backup
is a hope, not a plan. The Backup page has a **Verify** action that checks
integrity without restoring, and a **Restore** action that takes an automatic
safety backup first, requires password re-confirmation and a typed `RESTORE`
phrase, and signs every session out when it completes.

---

## 9. Network placement

- Put the terminals, the server and the administrator machines on the **same
  VLAN**, isolated from the general student network.
- Set `TRUSTED_WEB_CIDRS` and `TRUSTED_DEVICE_CIDRS` in `.env` to the actual
  ranges. Requests from outside them are refused by middleware before any
  authentication runs, so a stolen API key is useless from the wrong subnet.
- Give each terminal a **DHCP reservation**, and put that address in the device's
  IP allowlist on its detail page. A signed request from an unexpected address is
  refused and logged as an anomaly.
- Open only 443 (web) and 8443 (realtime WebSocket) inbound. Nothing needs
  outbound internet access.

---

## 10. Going live — checklist

- [ ] `APP_ENV=production` and `APP_DEBUG=false` in `.env`
- [ ] `APP_KEY`, `API_KEY_PEPPER` and `REALTIME_TICKET_SECRET` generated, not left blank
- [ ] `.env` is `chmod 640`, owned `root:www-data`
- [ ] `.env` points at `lsiams_app`, not `lsiams_migrate`
- [ ] The immutability REVOKEs have been applied
- [ ] TLS certificate installed; HTTP redirects to HTTPS
- [ ] The internal CA root is installed on every terminal
- [ ] `TRUSTED_WEB_CIDRS` and `TRUSTED_DEVICE_CIDRS` reflect the real network
- [ ] `lsiams-worker` and `lsiams-realtime` are enabled and running
- [ ] Nightly backup cron in place, and the off-machine copy is running
- [ ] A restore has been tested on a scratch database
- [ ] `php bin/console security:audit-keys` passes
- [ ] The first administrator's password has been changed from the installer's
- [ ] Every terminal has been claimed and shows **online** on the Devices page

---

## 11. Upgrading

```bash
cd /var/www/lsiams

sudo systemctl stop lsiams-worker lsiams-realtime
php bin/console backup                     # before anything else

git pull

# Migrations need DDL rights — switch DB_USER to lsiams_migrate for this step.
php bin/console migrate
php bin/console seed                       # idempotent: picks up new permissions and settings
# switch DB_USER back to lsiams_app

sudo systemctl start lsiams-worker lsiams-realtime
sudo systemctl reload nginx
```

`seed` without `--demo` is safe to re-run on a live system — it inserts only
missing reference rows and updates descriptions. That is exactly when you want
it: after an upgrade that adds a permission or a setting.

---

## 12. Troubleshooting

**A terminal shows `SIGNATURE_INVALID`.** Almost always clock drift. The
signature covers a timestamp, and the server rejects anything outside its
tolerance window. Check the terminal's time against `GET /api/device/time`.

**A terminal shows `DEVICE_UNCLAIMED`.** It has credentials but has never
presented its single-use claim token. Re-flash it with a fresh provisioning file
from the device detail page.

**Taps are accepted but no session is open.** Sessions open only on fingerprint
verification by a teacher scheduled for that classroom at that time. Check the
schedule, and check the teacher's fingerprint is enrolled.

**Sessions stay open past the end of the period.** The worker is not running.
`systemctl status lsiams-worker`.

**The connection pill shows "polling".** The WebSocket server is unreachable, so
the front-end degraded. Everything still works, with a few seconds more latency.
`systemctl status lsiams-realtime`, and check that 8443 is open and that the
terminal's TLS certificate covers the address in `REALTIME_WS_PUBLIC_URL`.

**Deadlock retries appear in the log.** Under normal load this is expected
occasionally and handled. If it is constant, `innodb_lock_wait_timeout` is
probably set higher than 5, or a long-running report is holding locks.
