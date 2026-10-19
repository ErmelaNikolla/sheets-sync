#!/usr/bin/env bash
#
# Sheets Sync – full setup on a fresh Ubuntu 22.04 / 24.04 server.
#
# Installs PHP 8.3, MySQL, Nginx and Composer, configures the app, creates the
# database, runs migrations, and registers the scheduler (cron) and the queue
# worker (systemd). Safe to run again: existing .env values and passwords are kept.
#
# Usage (from the project directory):
#   sudo ./setup.sh [--demo] [--domain=sheets.example.com] [--credentials=/path/key.json]
#                   [--admin-email=me@example.com] [--admin-password=secret]
#
#   --demo           Use the fake driver with demo spreadsheets (no Google account needed)
#   --domain         Server name for Nginx (default: server IP / localhost)
#   --credentials    Google service account JSON key to install
#
set -euo pipefail

APP_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_USER="www-data"
PHP_VERSION="8.3"
DB_NAME="sheets_sync"
DB_USER="sheets_sync"
DEMO=0
DOMAIN=""
CREDENTIALS=""
ADMIN_EMAIL="admin@example.com"
ADMIN_PASSWORD=""

for arg in "$@"; do
    case "$arg" in
        --demo) DEMO=1 ;;
        --domain=*) DOMAIN="${arg#*=}" ;;
        --credentials=*) CREDENTIALS="${arg#*=}" ;;
        --admin-email=*) ADMIN_EMAIL="${arg#*=}" ;;
        --admin-password=*) ADMIN_PASSWORD="${arg#*=}" ;;
        -h|--help) sed -n '2,20p' "$0"; exit 0 ;;
        *) echo "Unknown option: $arg" >&2; exit 1 ;;
    esac
done

step() { echo -e "\n\033[1;32m==>\033[0m \033[1m$*\033[0m"; }
warn() { echo -e "\033[1;33mWARNING:\033[0m $*"; }
fail() { echo -e "\033[1;31mERROR:\033[0m $*" >&2; exit 1; }

[[ $EUID -eq 0 ]] || fail "Run as root: sudo ./setup.sh"
. /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || warn "This script targets Ubuntu, detected ${PRETTY_NAME:-unknown}."

# systemd is missing in containers (e.g. testing this script in Docker).
if [[ "$(ps -p 1 -o comm= 2>/dev/null)" == "systemd" ]]; then HAS_SYSTEMD=1; else HAS_SYSTEMD=0; fi

svc() { # svc <action> <service>
    if [[ $HAS_SYSTEMD -eq 1 ]]; then systemctl "$1" "$2"; else service "$2" "$1" || true; fi
}

set_env() { # set_env KEY VALUE  – add or replace a key in .env
    local key="$1" value="$2"
    if grep -qE "^#?\s*${key}=" .env; then
        sed -i -E "s|^#?\s*${key}=.*|${key}=${value}|" .env
    else
        echo "${key}=${value}" >> .env
    fi
}

get_env() { grep -E "^${1}=" .env 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"' || true; }

# ---------------------------------------------------------------------------
step "Installing system packages"
export DEBIAN_FRONTEND=noninteractive
apt-get update -y
apt-get install -y software-properties-common ca-certificates curl git unzip cron

if ! apt-cache show "php${PHP_VERSION}-cli" >/dev/null 2>&1; then
    # Ubuntu 22.04 ships PHP 8.1; Filament needs 8.2+.
    add-apt-repository -y ppa:ondrej/php
    apt-get update -y
fi

apt-get install -y nginx mysql-server \
    "php${PHP_VERSION}-cli" "php${PHP_VERSION}-fpm" "php${PHP_VERSION}-mysql" "php${PHP_VERSION}-sqlite3" \
    "php${PHP_VERSION}-mbstring" "php${PHP_VERSION}-xml" "php${PHP_VERSION}-curl" "php${PHP_VERSION}-zip" \
    "php${PHP_VERSION}-intl" "php${PHP_VERSION}-bcmath" "php${PHP_VERSION}-gd"

svc start mysql
svc start "php${PHP_VERSION}-fpm"
[[ $HAS_SYSTEMD -eq 1 ]] && systemctl enable --now mysql "php${PHP_VERSION}-fpm" nginx cron >/dev/null

# ---------------------------------------------------------------------------
step "Installing Composer"
if ! command -v composer >/dev/null; then
    EXPECTED="$(curl -fsSL https://composer.github.io/installer.sig)"
    curl -fsSL https://getcomposer.org/installer -o /tmp/composer-setup.php
    ACTUAL="$(php -r "echo hash_file('sha384', '/tmp/composer-setup.php');")"
    [[ "$EXPECTED" == "$ACTUAL" ]] || fail "Composer installer checksum mismatch"
    php /tmp/composer-setup.php --quiet --install-dir=/usr/local/bin --filename=composer
    rm /tmp/composer-setup.php
fi

# ---------------------------------------------------------------------------
cd "$APP_DIR"
step "Configuring the application in $APP_DIR"

[[ -f .env ]] || cp .env.example .env

DB_PASSWORD="$(get_env DB_PASSWORD)"
if [[ -z "$DB_PASSWORD" || "$(get_env DB_USERNAME)" != "$DB_USER" ]]; then
    DB_PASSWORD="$(openssl rand -hex 16)"
fi

[[ -n "$ADMIN_PASSWORD" ]] || ADMIN_PASSWORD="$(get_env ADMIN_PASSWORD)"
[[ -n "$ADMIN_PASSWORD" && "$ADMIN_PASSWORD" != "password" ]] || ADMIN_PASSWORD="$(openssl rand -base64 12 | tr -d '/+=')"

SERVER_NAME="${DOMAIN:-$(hostname -I 2>/dev/null | awk '{print $1}')}"
SERVER_NAME="${SERVER_NAME:-localhost}"

set_env APP_NAME '"Sheets Sync"'
set_env APP_ENV production
set_env APP_DEBUG false
set_env APP_URL "http://${SERVER_NAME}"
set_env DB_CONNECTION mysql
set_env DB_HOST 127.0.0.1
set_env DB_PORT 3306
set_env DB_DATABASE "$DB_NAME"
set_env DB_USERNAME "$DB_USER"
set_env DB_PASSWORD "$DB_PASSWORD"
set_env QUEUE_CONNECTION database
set_env ADMIN_EMAIL "$ADMIN_EMAIL"
set_env ADMIN_PASSWORD "$ADMIN_PASSWORD"
set_env SHEETS_DRIVER "$([[ $DEMO -eq 1 ]] && echo fake || echo google)"

if [[ -n "$CREDENTIALS" ]]; then
    [[ -f "$CREDENTIALS" ]] || fail "Credentials file not found: $CREDENTIALS"
    mkdir -p storage/app/google
    cp "$CREDENTIALS" storage/app/google/service-account.json
    chmod 640 storage/app/google/service-account.json
    set_env GOOGLE_APPLICATION_CREDENTIALS storage/app/google/service-account.json
fi

# ---------------------------------------------------------------------------
step "Creating MySQL database and user"
mysql <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASSWORD}';
-- ALTER is needed: the sync creates tables and adds columns at runtime.
GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost';
FLUSH PRIVILEGES;
SQL

# ---------------------------------------------------------------------------
step "Installing PHP dependencies"
export COMPOSER_ALLOW_SUPERUSER=1
composer install --no-dev --optimize-autoloader --no-interaction --no-progress

[[ -n "$(get_env APP_KEY)" ]] || php artisan key:generate --force

step "Running migrations and seeding"
# A cached config from a previous run would hide the new .env. Not optimize:clear:
# its cache:clear needs the `cache` table, which does not exist before the first migrate.
php artisan config:clear >/dev/null
php artisan route:clear >/dev/null
php artisan view:clear >/dev/null
php artisan migrate --force
php artisan db:seed --force
php artisan filament:assets >/dev/null
php artisan optimize

chown -R "$APP_USER":"$APP_USER" storage bootstrap/cache
chmod -R ug+rwX storage bootstrap/cache

# ---------------------------------------------------------------------------
step "Configuring Nginx"
cat > /etc/nginx/sites-available/sheets-sync <<NGINX
server {
    listen 80;
    server_name ${SERVER_NAME};
    root ${APP_DIR}/public;
    index index.php;
    charset utf-8;
    client_max_body_size 10m;

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    location ~ \.php\$ {
        fastcgi_pass unix:/run/php/php${PHP_VERSION}-fpm.sock;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_read_timeout 300;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
NGINX
ln -sf /etc/nginx/sites-available/sheets-sync /etc/nginx/sites-enabled/sheets-sync
rm -f /etc/nginx/sites-enabled/default
nginx -t
svc restart nginx

# ---------------------------------------------------------------------------
step "Registering the scheduler (cron) and the queue worker"
cat > /etc/cron.d/sheets-sync <<CRON
# Laravel scheduler: runs "sheets:sync" daily at SHEETS_SYNC_AT
* * * * * ${APP_USER} cd ${APP_DIR} && php artisan schedule:run >> /dev/null 2>&1
CRON
chmod 644 /etc/cron.d/sheets-sync
svc restart cron

WORKER_CMD="/usr/bin/php ${APP_DIR}/artisan queue:work --sleep=3 --tries=1 --timeout=3600"
if [[ $HAS_SYSTEMD -eq 1 ]]; then
    cat > /etc/systemd/system/sheets-sync-worker.service <<UNIT
[Unit]
Description=Sheets Sync queue worker (UI "Sync now" jobs)
After=network.target mysql.service

[Service]
User=${APP_USER}
WorkingDirectory=${APP_DIR}
ExecStart=${WORKER_CMD}
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
    systemctl daemon-reload
    systemctl enable --now sheets-sync-worker
    systemctl restart sheets-sync-worker
else
    warn "systemd not available: starting the queue worker in the background for this session only."
    pkill -f "artisan queue:work" || true
    su -s /bin/bash "$APP_USER" -c "nohup ${WORKER_CMD} >> ${APP_DIR}/storage/logs/worker.log 2>&1 &"
fi

# ---------------------------------------------------------------------------
if [[ $DEMO -eq 1 ]]; then
    step "Running a first sync with the demo spreadsheets"
    su -s /bin/bash "$APP_USER" -c "cd ${APP_DIR} && php artisan sheets:sync --trigger=cli" || warn "Demo sync failed, see storage/logs."
fi

cat <<DONE

$(echo -e "\033[1;32m✔ Sheets Sync is installed.\033[0m")

  URL:       http://${SERVER_NAME}/admin
  Login:     ${ADMIN_EMAIL}
  Password:  ${ADMIN_PASSWORD}      (stored in ${APP_DIR}/.env as ADMIN_PASSWORD)
  Driver:    $( [[ $DEMO -eq 1 ]] && echo "fake (demo spreadsheets)" || echo "google" )

  Daily sync: $(cd "$APP_DIR" && get_env SHEETS_SYNC_AT || true) via /etc/cron.d/sheets-sync
  Manual run: cd ${APP_DIR} && sudo -u ${APP_USER} php artisan sheets:sync

DONE

if [[ $DEMO -eq 0 && ! -f "$APP_DIR/storage/app/google/service-account.json" ]]; then
    warn "No Google credentials installed yet. Follow README › Google API credentials, then run:"
    echo "    sudo ./setup.sh --credentials=/path/to/service-account.json"
fi
