#!/usr/bin/env bash
# Encrypted backup of the database and private files, run on the server.
#
# Encrypted to a PUBLIC key, so the server can make backups but can never read them:
# the private key stays with you (password manager or offline). Create the key pair once,
# on your own computer, never on the server:
#   openssl genpkey -algorithm RSA -pkeyopt rsa_keygen_bits:4096 -out ledger-backup-private.pem
#   openssl pkey -in ledger-backup-private.pem -pubout -out backup_public.pem
# then upload backup_public.pem to ~/domains/<domain>/app/shared/backup_public.pem
#
# Cron (daily at 02:17):  17 2 * * * bash ~/domains/<domain>/app/current/deploy/backup.sh
# Restore: see README ("Restoring a backup").
set -euo pipefail
cd "$(dirname "$0")/.."
SHARED="$(cd ../../shared && pwd)"
OUT="${BACKUP_DIR:-$HOME/ledger-backups}"
KEEP_DAYS="${KEEP_DAYS:-30}"
PUBKEY="${SHARED}/backup_public.pem"
mkdir -p "$OUT" && chmod 700 "$OUT"

[ -f "$PUBKEY" ] || { echo "No ${PUBKEY}; see the instructions at the top of this script."; exit 1; }

env_value() { grep -E "^$1=" "${SHARED}/.env" | tail -1 | cut -d= -f2- | sed -e 's/^"//' -e 's/"$//'; }
DB_NAME="$(env_value DB_DATABASE)"; DB_USER="$(env_value DB_USERNAME)"; DB_PASS="$(env_value DB_PASSWORD)"
DB_HOST="$(env_value DB_HOST)"; DB_HOST="${DB_HOST:-127.0.0.1}"

STAMP="$(date -u +%Y%m%dT%H%M%SZ)${1:+-pre-deploy}"
WORK="$(mktemp -d)"; trap 'rm -rf "$WORK"' EXIT
umask 077

MYSQL_PWD="$DB_PASS" mysqldump --single-transaction --routines --no-tablespaces -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" > "$WORK/database.sql"
tar -czf "$WORK/payload.tar.gz" -C "$WORK" database.sql -C "$SHARED/storage/app" private

# Hybrid encryption: a random key encrypts the archive; the public key encrypts that random key.
openssl rand -out "$WORK/key.bin" 32
openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -in "$WORK/payload.tar.gz" -out "$WORK/payload.enc" -pass "file:$WORK/key.bin"
openssl pkeyutl -encrypt -pubin -inkey "$PUBKEY" -pkeyopt rsa_padding_mode:oaep -in "$WORK/key.bin" -out "$WORK/key.enc"
tar -cf "$OUT/ledger-${STAMP}.tar" -C "$WORK" payload.enc key.enc
sha256sum "$OUT/ledger-${STAMP}.tar" > "$OUT/ledger-${STAMP}.tar.sha256"

find "$OUT" -name 'ledger-*.tar*' -mtime +"$KEEP_DAYS" -delete
echo "Backup written: $OUT/ledger-${STAMP}.tar"
