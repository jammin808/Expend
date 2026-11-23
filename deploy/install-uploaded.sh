#!/bin/bash
# Installs a release that was uploaded next to this script (when there's no SSH, for example
# through Hostinger's file upload API) and is run once by a temporary cron job:
#
#   <upload dir>/install-uploaded.sh   this script
#   <upload dir>/RELEASE               release name, e.g. 20261123001619-b97c5eb
#   <upload dir>/release.tar.gz        the built app (vendor and public/build included)
#   <upload dir>/env                   first install only: the server's .env (moved out of the web root at once)
#   <upload dir>/.htaccess             "Require all denied", so nothing here is ever served
#
# It does what deploy.sh does over SSH: new release folder, shared .env and storage, migrations,
# caches, then an atomic switch of app/current. On the first install it also points public_html
# at app/current/public so that only public/ is reachable. It logs to app/install-<release>.log,
# prints that log on every run (the cron output) and does nothing once the release is installed.
set -euo pipefail

DOMAIN="${DOMAIN:-expenses.rohleder.tech}"
BASE="${BASE:-$HOME/domains/$DOMAIN}"
APP="$BASE/app"
PHP="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
HERE="$(cd "$(dirname "$0")" && pwd -P)"
RELEASE="$(tr -dc 'A-Za-z0-9-' < "$HERE/RELEASE")"
R="$APP/releases/$RELEASE"
LOG="$APP/install-$RELEASE.log"

grep -q "Require all denied" "$HERE/.htaccess" 2>/dev/null || { echo "Refusing to run: $HERE/.htaccess must deny web access."; exit 1; }
mkdir -p "$APP"
if [ -f "$R/.installed" ] || [ -f "$LOG.failed" ]; then
    cat "$LOG"
    exit 0
fi
mkdir "$APP/.install.lock" 2>/dev/null || { echo "An install is already running."; exit 0; }

exec 3>&1 >>"$LOG" 2>&1
trap 'status=$?; [ $status -eq 0 ] || { echo "FAILED (exit $status)"; touch "$LOG.failed"; }; rmdir "$APP/.install.lock"; cat "$LOG" >&3' EXIT
step() { echo "[$(date -u +%H:%M:%S)] $*"; }

step "Installing $RELEASE with $($PHP -r 'echo PHP_VERSION;')"
mkdir -p "$APP/releases" "$APP/shared/storage/app/private" "$APP/shared/storage/app/public" \
    "$APP/shared/storage/framework/cache/data" "$APP/shared/storage/framework/sessions" \
    "$APP/shared/storage/framework/views" "$APP/shared/storage/logs"

if [ -f "$HERE/env" ]; then
    if [ -f "$APP/shared/.env" ]; then
        step "Keeping the existing shared/.env; discarding the uploaded one"
        rm -f "$HERE/env"
    else
        mv "$HERE/env" "$APP/shared/.env"
        step "Moved the settings file to shared/.env"
    fi
fi
[ -f "$APP/shared/.env" ] || { step "No shared/.env"; exit 1; }
chmod 600 "$APP/shared/.env"

step "Unpacking"
rm -rf "$R" && mkdir -p "$R"
tar -xzf "$HERE/release.tar.gz" -C "$R"
rm -f "$HERE/release.tar.gz"
cd "$R"
ln -sfn ../../shared/.env .env
rm -rf storage && ln -sfn ../../shared/storage storage
mkdir -p bootstrap/cache

if [ -L "$APP/current" ]; then
    if [ -f "$APP/shared/backup_public.pem" ]; then
        step "Backing up the database before migrating (encrypted)"
        bash deploy/backup.sh --pre-deploy
    else
        # No backup key yet: keep a plain copy outside the web root, readable only by this account, last 5 kept.
        # It stops the deploy if the dump fails. Add backup_public.pem to get encrypted backups instead.
        step "Backing up the database before migrating (server-only copy in ~/ledger-backups)"
        env_value() { grep -E "^$1=" "$APP/shared/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
        mkdir -p "$HOME/ledger-backups" && chmod 700 "$HOME/ledger-backups"
        OUT="$HOME/ledger-backups/pre-deploy-$RELEASE.sql.gz"
        (umask 077; MYSQL_PWD="$(env_value DB_PASSWORD)" mysqldump --single-transaction --no-tablespaces \
            -h "$(env_value DB_HOST)" -u "$(env_value DB_USERNAME)" "$(env_value DB_DATABASE)" | gzip > "$OUT")
        step "Saved $(du -h "$OUT" | cut -f1) to ~/ledger-backups"
        for old in $(ls -1t "$HOME"/ledger-backups/pre-deploy-*.sql.gz | tail -n +6); do rm -f "$old"; done
    fi
fi

step "Migrating"
"$PHP" artisan migrate --force --no-interaction
step "Caching config, routes, events and views"
"$PHP" artisan optimize --no-interaction

# Keep a copy of this script (still web-denied) at the same public path in the new release, so the
# cron job's later runs just print this log until it is removed.
UPLOAD_DIR_NAME="$(basename "$HERE")"
mkdir -p "$R/public/$UPLOAD_DIR_NAME"
cp "$HERE/.htaccess" "$HERE/install-uploaded.sh" "$HERE/RELEASE" "$R/public/$UPLOAD_DIR_NAME/"

step "Switching app/current"
ln -sfn "releases/$RELEASE" "$APP/current.new" && mv -Tf "$APP/current.new" "$APP/current"

if [ ! -L "$BASE/public_html" ]; then
    step "Pointing public_html at app/current/public (the original is kept as public_html.original)"
    rm -rf "$BASE/public_html.original"
    mv "$BASE/public_html" "$BASE/public_html.original"
    ln -s app/current/public "$BASE/public_html"
    rm -rf "$BASE/public_html.original/$UPLOAD_DIR_NAME"
    find "$BASE/public_html.original" -maxdepth 1 -name 'probe-*.php' -delete
else
    rm -rf "$HERE"
fi

touch "$R/.installed"
step "Removing old releases (keeping 5)"
cd "$APP/releases"
for old in $(ls -1t | tail -n +6); do
    if [ "$APP/releases/$old" != "$R" ]; then rm -rf "${APP:?}/releases/$old"; fi
done
cd "$R"
"$PHP" artisan migrate:status --no-interaction | tail -4
step "Installed $RELEASE"
