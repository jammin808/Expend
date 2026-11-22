#!/usr/bin/env bash
# Atomic deploy to Hostinger shared hosting over SSH.
#
# Each deploy goes into its own release folder; the live site only switches over once
# migrations and a health check succeed. On failure it switches back.
#
# Usage (from a clean checkout, after `composer install --no-dev` and `npm run build`):
#   DEPLOY_HOST=u123456789@145.x.x.x DEPLOY_PORT=65002 DEPLOY_DOMAIN=expenses.rohleder.tech ./deploy/deploy.sh
#
# Server layout (created on first run):
#   ~/domains/$DEPLOY_DOMAIN/app/releases/<timestamp>-<sha>/
#   ~/domains/$DEPLOY_DOMAIN/app/current -> releases/<...>
#   ~/domains/$DEPLOY_DOMAIN/app/shared/.env        (you create this once; mode 600)
#   ~/domains/$DEPLOY_DOMAIN/app/shared/storage/
#   ~/domains/$DEPLOY_DOMAIN/public_html -> app/current/public   (only public/ is web-reachable)
set -euo pipefail

: "${DEPLOY_HOST:?Set DEPLOY_HOST, e.g. u123456789@145.x.x.x}"
: "${DEPLOY_DOMAIN:?Set DEPLOY_DOMAIN, e.g. expenses.rohleder.tech}"
DEPLOY_PORT="${DEPLOY_PORT:-65002}"
PHP_BIN="${PHP_BIN:-/opt/alt/php84/usr/bin/php}"
KEEP="${KEEP:-5}"

SHA="$(git rev-parse --short HEAD)"
RELEASE="$(date -u +%Y%m%d%H%M%S)-${SHA}"
APP="domains/${DEPLOY_DOMAIN}/app"
SSH=(ssh -p "$DEPLOY_PORT" "$DEPLOY_HOST")

[ -f public/build/manifest.json ] || { echo "Build the front end first: npm run build"; exit 1; }
[ -d vendor ] || { echo "Install PHP dependencies first: composer install --no-dev --optimize-autoloader"; exit 1; }

echo "→ Packing release ${RELEASE}"
TARBALL="$(mktemp -t ledger-XXXXXX).tar.gz"
tar --exclude=.git --exclude=node_modules --exclude=tests --exclude=storage --exclude=.env \
    --exclude=database/database.sqlite -czf "$TARBALL" .

echo "→ Uploading"
"${SSH[@]}" "mkdir -p ${APP}/releases/${RELEASE} ${APP}/shared/storage/{app/private,framework/{cache,sessions,views},logs}"
scp -P "$DEPLOY_PORT" -q "$TARBALL" "${DEPLOY_HOST}:${APP}/releases/${RELEASE}/release.tar.gz"
rm -f "$TARBALL"

echo "→ Installing, backing up the database, migrating"
"${SSH[@]}" bash -s <<REMOTE
set -euo pipefail
cd ${APP}
[ -f shared/.env ] || { echo "Create ${APP}/shared/.env first (see README)"; exit 1; }
chmod 600 shared/.env
cd releases/${RELEASE}
tar -xzf release.tar.gz && rm release.tar.gz
ln -sfn ../../shared/.env .env
rm -rf storage && ln -sfn ../../shared/storage storage
bash deploy/backup.sh --pre-deploy || { echo "Pre-deploy backup failed; stopping."; exit 1; }
${PHP_BIN} artisan migrate --force
${PHP_BIN} artisan config:cache
${PHP_BIN} artisan route:cache
${PHP_BIN} artisan view:cache
REMOTE

echo "→ Switching to the new release"
"${SSH[@]}" bash -s <<REMOTE
set -euo pipefail
cd ${APP}
PREVIOUS="\$(readlink current || true)"
echo "\$PREVIOUS" > previous_release
ln -sfn releases/${RELEASE} current.new && mv -Tf current.new current
REMOTE

echo "→ Health check"
if ! curl -fsS --max-time 20 "https://${DEPLOY_DOMAIN}/up" > /dev/null; then
    echo "Health check failed. Rolling back."
    "${SSH[@]}" "cd ${APP} && P=\$(cat previous_release) && [ -n \"\$P\" ] && ln -sfn \"\$P\" current.new && mv -Tf current.new current"
    exit 1
fi

for path in .env composer.json storage/logs/laravel.log vendor/autoload.php; do
    code="$(curl -s -o /dev/null -w '%{http_code}' "https://${DEPLOY_DOMAIN}/${path}")"
    if [ "$code" != "404" ] && [ "$code" != "403" ]; then
        echo "WARNING: /${path} answered ${code}. Only public/ should be reachable. Check the document root."
    fi
done

echo "→ Removing old releases (keeping ${KEEP})"
"${SSH[@]}" "cd ${APP}/releases && ls -1t | tail -n +$((KEEP + 1)) | xargs -r rm -rf"

echo "✓ Deployed ${RELEASE}"
