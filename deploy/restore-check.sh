#!/usr/bin/env bash
# Check that an encrypted backup can really be restored. Run it on your own computer, where the private key is,
# never on the server.
#
#   bash restore-check.sh ledger-<stamp>.tar ledger-backup-private.pem
#       decrypts the backup into a temporary folder, checks the archive's checksum, that the database dump is complete
#       and has the tables that hold your records, and counts the uploaded files; then deletes the decrypted copy.
#
#   bash restore-check.sh ledger-<stamp>.tar ledger-backup-private.pem --load <empty database>
#       also loads the dump into that database (it must exist and be empty), counts the rows in the main tables, and
#       checks the audit trail's chain in the loaded copy, event by event, as `php artisan ledger:verify-audit` does.
#       Connection: RESTORE_DB_HOST (default 127.0.0.1), RESTORE_DB_PORT (3306), RESTORE_DB_USER, and the password in
#       MYSQL_PWD; RESTORE_DB_TZ is the live database's time zone (default +00:00, UTC, as on the server). Needs
#       MariaDB 10.2+ or MySQL 8. Use a throwaway local database, never the live one.
#
#   --keep <folder>   keep the decrypted database.sql and private/ files there instead of deleting them.
#
# Exit code 0 means every check passed.
set -euo pipefail

usage() { sed -n '2,17p' "$0" | sed 's/^# \{0,1\}//'; exit 2; }
[ $# -ge 2 ] || usage
BACKUP="$1"; KEY="$2"; shift 2
LOAD=""; KEEP=""
while [ $# -gt 0 ]; do
    case "$1" in
        --load) LOAD="${2:?--load needs a database name}"; shift 2 ;;
        --keep) KEEP="${2:?--keep needs a folder}"; shift 2 ;;
        *) usage ;;
    esac
done
[ -f "$BACKUP" ] || { echo "No backup file at $BACKUP"; exit 1; }
[ -f "$KEY" ] || { echo "No private key at $KEY"; exit 1; }

FAILED=0
ok() { echo "  ok    $*"; }
bad() { echo "  FAIL  $*"; FAILED=1; }

umask 077
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

echo "Checking $(basename "$BACKUP")"

if [ -f "$BACKUP.sha256" ]; then
    expected="$(cut -d' ' -f1 "$BACKUP.sha256")"
    actual="$( (sha256sum "$BACKUP" 2>/dev/null || shasum -a 256 "$BACKUP") | cut -d' ' -f1)"
    [ "$expected" = "$actual" ] && ok "checksum matches" || bad "checksum doesn't match: the file is damaged or incomplete"
else
    echo "  --    no $(basename "$BACKUP").sha256 next to it, so the checksum isn't checked"
fi

tar -xf "$BACKUP" -C "$WORK" payload.enc key.enc 2>/dev/null || { bad "not a backup made by backup.sh (no payload.enc and key.enc inside)"; exit 1; }
openssl pkeyutl -decrypt -inkey "$KEY" -pkeyopt rsa_padding_mode:oaep -in "$WORK/key.enc" -out "$WORK/key.bin" 2>/dev/null \
    || { bad "this private key can't open the backup (wrong key?)"; exit 1; }
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -in "$WORK/payload.enc" -out "$WORK/payload.tar.gz" -pass "file:$WORK/key.bin" 2>/dev/null \
    || { bad "decryption failed: the backup is damaged"; exit 1; }
rm -f "$WORK/key.bin"
ok "decrypted with your private key"

mkdir "$WORK/out"
tar -xzf "$WORK/payload.tar.gz" -C "$WORK/out" || { bad "the decrypted archive is damaged"; exit 1; }
SQL="$WORK/out/database.sql"
[ -s "$SQL" ] || { bad "no database.sql inside"; exit 1; }

if tail -n 3 "$SQL" | grep -q -- '-- Dump completed'; then ok "database dump is complete"; else bad "database dump stops early (no 'Dump completed' line)"; fi

tables="$(grep -c '^CREATE TABLE' "$SQL" || true)"
missing=""
for t in migrations users accounts bank_transactions allocations categories work_jobs documents invoices audit_events tax_years settings; do
    grep -q "^CREATE TABLE \`$t\`" "$SQL" || missing="$missing $t"
done
[ -z "$missing" ] && ok "$tables tables, including all the main ones" || bad "$tables tables, but these are missing:$missing"

files="$(find "$WORK/out/private" -type f 2>/dev/null | wc -l | tr -d ' ')"
size="$(du -sh "$WORK/out/private" 2>/dev/null | cut -f1)"
[ -d "$WORK/out/private" ] && ok "uploaded files: $files (${size:-0})" || bad "no private/ folder of uploaded files"

# The audit trail in the loaded copy, checked as AuditLog::verify() does (keep the two in step): every event's hash is
# the SHA-256 of its fields as AuditLog::hashFor() joins them, each event names the one before it, and the chain's head
# points at the last event. Times are read in the live database's time zone, as the app wrote them.
check_audit_chain() {
    local tz="${RESTORE_DB_TZ:-+00:00}" result total broken head good
    result="$(q "SET time_zone = '${tz//\'/}';
        SELECT COUNT(*), COALESCE(MIN(CASE WHEN ok = 1 THEN NULL ELSE id END), 0),
            (SELECT COUNT(*) FROM audit_heads h WHERE h.id = 1
                AND NOT (h.hash <=> (SELECT e.hash FROM audit_events e ORDER BY e.id DESC LIMIT 1)))
        FROM (SELECT id, (prev_hash <=> LAG(hash) OVER (ORDER BY id)) AND hash = SHA2(CONCAT_WS('|',
                COALESCE(prev_hash, ''), DATE_FORMAT(occurred_at, '%Y-%m-%d %H:%i:%s'), actor, action, subject_type,
                COALESCE(subject_id, ''), COALESCE(\`before\`, 'null'), COALESCE(\`after\`, 'null'), COALESCE(reason, '')), 256) AS ok
            FROM audit_events) checked")" || { bad "couldn't check the audit trail's chain (see the error above)"; return; }
    read -r total broken head <<< "$result"
    if [ "$broken" != "0" ]; then
        good="$(q "SELECT COUNT(*) FROM audit_events WHERE id < $broken")"
        bad "audit trail's chain broken at event $broken after $good good events: an event was changed, added or removed"
    elif [ "$head" != "0" ]; then
        bad "audit trail's chain doesn't end at its last event ($total events): events were removed from the end"
    else
        ok "audit trail's chain intact ($total events)"
    fi
}

if [ -n "$LOAD" ]; then
    HOST="${RESTORE_DB_HOST:-127.0.0.1}"; PORT="${RESTORE_DB_PORT:-3306}"; USER="${RESTORE_DB_USER:?set RESTORE_DB_USER (and the password in MYSQL_PWD)}"
    q() { mysql -h "$HOST" -P "$PORT" -u "$USER" -N -B "$LOAD" -e "$1"; }
    existing="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE()")" || { bad "can't connect to database $LOAD"; exit 1; }
    [ "$existing" = "0" ] || { bad "database $LOAD isn't empty ($existing tables): use a new, empty one"; exit 1; }
    if mysql -h "$HOST" -P "$PORT" -u "$USER" "$LOAD" < "$SQL"; then
        ok "loaded into $LOAD"
        loaded="$(q "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")"
        [ "$loaded" = "$tables" ] && ok "all $tables tables restored" || bad "$loaded of $tables tables restored"
        for t in bank_transactions allocations documents invoices audit_events; do
            echo "        $t: $(q "SELECT COUNT(*) FROM \`$t\`") rows"
        done
        echo "        latest migration: $(q "SELECT migration FROM migrations ORDER BY id DESC LIMIT 1")"
        check_audit_chain
    else
        bad "loading the dump into $LOAD failed (see the error above)"
    fi
fi

if [ -n "$KEEP" ]; then
    mkdir -p "$KEEP" && cp -R "$WORK/out/." "$KEEP/" && echo "  Decrypted copy kept in $KEEP (it holds your records: delete it when done)"
fi

if [ "$FAILED" = 0 ]; then echo "Backup restores correctly."; else echo "Backup check FAILED."; fi
exit "$FAILED"
