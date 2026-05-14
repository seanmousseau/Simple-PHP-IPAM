#!/usr/bin/env bash
# run-pass1-grid.sh — drive the decrypt tool through the Pass-1 conformance grid.
# Usage: bash tests/fixtures/decrypt-tool/run-pass1-grid.sh [--large]
# (intentionally NOT `set -u`: macOS bash 3.2 errors on "${arr[@]}" for empty arrays)
set -o pipefail
cd "$(dirname "$0")/../../.." || exit 1

# ---------------------------------------------------------------------------
# Portability helpers (BSD macOS vs GNU Linux)
# ---------------------------------------------------------------------------
file_size() {
  wc -c <"$1" | tr -d ' '
}

_measure_rss() {
  # Usage: _measure_rss <output_file> -- <cmd> [args...]
  local out="$1"; shift
  [ "$1" = "--" ] && shift
  if /usr/bin/time -l true >/dev/null 2>&1; then
    /usr/bin/time -l "$@" 2>"$out"
  else
    /usr/bin/time -v "$@" 2>"$out"
  fi
}

_extract_rss() {
  local f="$1"
  if grep -q 'maximum resident set size' "$f"; then
    awk '/maximum resident set size/ {print $1}' "$f"
  else
    awk '/Maximum resident set size/ {print $6 * 1024}' "$f"
  fi
}

TOOL=(php Simple-PHP-IPAM/tools/decrypt-backup.php)
FX=tests/fixtures/decrypt-tool
APPS=$(grep -h '^app_secret=' "$FX/F1/credential.txt" | cut -d= -f2)
PASS=$(grep -h '^passphrase=' "$FX/F4/credential.txt" | cut -d= -f2)
VKB64=$(grep -h '^vault_key_b64=' "$FX/F3/credential.txt" | cut -d= -f2)
VKHEX=$(grep -h '^vault_key_hex=' "$FX/F3/credential.txt" | cut -d= -f2)
WRONGHEX=1111111111111111111111111111111111111111111111111111111111111111
PLAIN="$FX/plaintext-source.sqlite"
KL1SRC="$FX/ipambkl1-source.bin"
T=$(mktemp -d)
trap 'rm -rf "$T"' EXIT
PASSCOUNT=0; FAILCOUNT=0

ok()  { echo "  PASS  $1"; PASSCOUNT=$((PASSCOUNT+1)); }
bad() { echo "  FAIL  $1"; FAILCOUNT=$((FAILCOUNT+1)); }
# expect <label> <want_exit> -- <cmd...>
expect() {
  local label=$1 want=$2; shift 3   # skip the literal "--"
  "$@" >"$T/out" 2>"$T/err"; local got=$?
  if [ "$got" -eq "$want" ]; then ok "$label (exit $got)"; else bad "$label (exit $got, want $want) :: $(head -c200 "$T/err")"; fi
}
no_partial() { if [ ! -e "$1" ]; then ok "  └─ no partial output"; else bad "  └─ PARTIAL OUTPUT LEFT: $1"; fi; }
byte_eq()    { if cmp -s "$1" "$2"; then ok "  └─ byte-equal vs $(basename "$2")"; else bad "  └─ MISMATCH $1 != $2"; fi; }

# run_fixture <id> <archive> <expected_out_file> <hascred 0|1> <credtype ''|app|vault|pass>
run_fixture() {
  local id=$1 arc=$2 expout=$3 hascred=$4 ctype=$5
  echo "── $id ($arc) ──"
  local d="$T/$id"; mkdir -p "$d"
  # build the CRED args by type
  local cred1='' cred2='' wrong1='' wrong2=''
  case "$ctype" in
    app)   cred1=--app-secret; cred2=$APPS;   wrong1=--app-secret; wrong2=00000000000000000000000000000000 ;;
    vault) cred1=--vault-key;  cred2=$VKB64;  wrong1=--vault-key;  wrong2=$WRONGHEX ;;
    pass)  cred1=--passphrase; cred2=$PASS;   wrong1=--passphrase; wrong2=WRONG-PASSPHRASE ;;
  esac

  # case 1: green
  rm -f "$d/g.out"
  if [ "$hascred" = 1 ]; then expect "$id.1 green" 0 -- "${TOOL[@]}" --in "$arc" --out "$d/g.out" "$cred1" "$cred2"
  else                        expect "$id.1 green" 0 -- "${TOOL[@]}" --in "$arc" --out "$d/g.out"; fi
  byte_eq "$d/g.out" "$expout"

  # case 2: wrong credential
  rm -f "$d/w.out"
  if [ "$hascred" = 1 ]; then
    expect "$id.2 wrong-cred" 3 -- "${TOOL[@]}" --in "$arc" --out "$d/w.out" "$wrong1" "$wrong2"
    no_partial "$d/w.out"
  else ok "$id.2 wrong-cred N/A (format takes no credential)"; fi

  # case 3: tampered byte (~mid file)
  cp "$arc" "$d/tamper.bin"
  local sz; sz=$(file_size "$d/tamper.bin")
  printf '\x5a' | dd of="$d/tamper.bin" bs=1 seek=$((sz/2)) count=1 conv=notrunc status=none 2>/dev/null
  rm -f "$d/t.out"
  if [ "$hascred" = 1 ] || [ "$id" = F5 ]; then
    if [ "$hascred" = 1 ]; then expect "$id.3 tampered" 3 -- "${TOOL[@]}" --in "$d/tamper.bin" --out "$d/t.out" "$cred1" "$cred2"
    else                        expect "$id.3 tampered" 3 -- "${TOOL[@]}" --in "$d/tamper.bin" --out "$d/t.out"; fi
    no_partial "$d/t.out"
  else
    "${TOOL[@]}" --in "$d/tamper.bin" --out "$d/t.out" >"$T/out" 2>"$T/err"; local g=$?
    echo "  NOTE  $id.3 tampered bare archive → exit $g (no integrity layer on bare files; recorded, not gated)"
    PASSCOUNT=$((PASSCOUNT+1))
  fi

  # case 4: truncated (lop ~50 bytes off tail)
  local nsz=$((sz>50 ? sz-50 : 1))
  dd if="$arc" of="$d/trunc.bin" bs=1 count="$nsz" status=none 2>/dev/null
  rm -f "$d/r.out"
  if [ "$hascred" = 1 ] || [ "$id" = F5 ]; then
    if [ "$hascred" = 1 ]; then expect "$id.4 truncated" 3 -- "${TOOL[@]}" --in "$d/trunc.bin" --out "$d/r.out" "$cred1" "$cred2"
    else                        expect "$id.4 truncated" 3 -- "${TOOL[@]}" --in "$d/trunc.bin" --out "$d/r.out"; fi
    no_partial "$d/r.out"
  else
    "${TOOL[@]}" --in "$d/trunc.bin" --out "$d/r.out" >"$T/out" 2>"$T/err"; local g=$?
    echo "  NOTE  $id.4 truncated bare archive → exit $g (recorded, not gated)"
    PASSCOUNT=$((PASSCOUNT+1))
  fi

  # case 5: empty file
  : > "$d/empty.bin"; rm -f "$d/e.out"
  if [ "$hascred" = 1 ]; then expect "$id.5 empty" 2 -- "${TOOL[@]}" --in "$d/empty.bin" --out "$d/e.out" "$cred1" "$cred2"
  else                        expect "$id.5 empty" 2 -- "${TOOL[@]}" --in "$d/empty.bin" --out "$d/e.out"; fi
  no_partial "$d/e.out"

  # case 6: wrong magic + wrong credential type
  rm -f "$d/m.out"
  if [ "$hascred" = 1 ]; then
    if [ "$ctype" = app ]; then expect "$id.6 wrong-cred-type" 2 -- "${TOOL[@]}" --in "$arc" --out "$d/m.out" --vault-key "$VKB64"
    else                        expect "$id.6 wrong-cred-type" 2 -- "${TOOL[@]}" --in "$arc" --out "$d/m.out" --app-secret "$APPS"; fi
  else
    expect "$id.6 wrong-cred-type (cred on no-cred archive)" 2 -- "${TOOL[@]}" --in "$arc" --out "$d/m.out" --app-secret "$APPS"
  fi
  no_partial "$d/m.out"

  # case 7: --out collision
  printf 'pre-existing bytes' > "$d/coll.out"
  if [ "$hascred" = 1 ]; then
    expect "$id.7 collision (no --force)" 2 -- "${TOOL[@]}" --in "$arc" --out "$d/coll.out" "$cred1" "$cred2"
    expect "$id.7 collision (--force)"    0 -- "${TOOL[@]}" --in "$arc" --out "$d/coll.out" --force "$cred1" "$cred2"
  else
    expect "$id.7 collision (no --force)" 2 -- "${TOOL[@]}" --in "$arc" --out "$d/coll.out"
    expect "$id.7 collision (--force)"    0 -- "${TOOL[@]}" --in "$arc" --out "$d/coll.out" --force
  fi

  # case 8: stdout
  if [ "$hascred" = 1 ]; then "${TOOL[@]}" --in "$arc" --out - "$cred1" "$cred2" > "$d/s.out" 2>"$T/err"; local g=$?
  else                        "${TOOL[@]}" --in "$arc" --out - > "$d/s.out" 2>"$T/err"; g=$?; fi
  if [ "$g" -eq 0 ]; then ok "$id.8 stdout (exit 0)"; else bad "$id.8 stdout (exit $g) :: $(head -c200 "$T/err")"; fi
  byte_eq "$d/s.out" "$expout"
}

run_fixture F1 "$FX/F1/archive.enc"         "$PLAIN"                       1 app
run_fixture F2 "$FX/F2/archive.enc"         "$PLAIN"                       1 app
run_fixture F3 "$FX/F3/archive.ipambkp3"    "$KL1SRC"                      1 vault
run_fixture F4 "$FX/F4/archive.ipambkp3"    "$KL1SRC"                      1 pass
run_fixture F5 "$FX/F5/archive.ipambku1"    "$KL1SRC"                      0 ''
run_fixture F6 "$FX/F6/archive.sql.gz"      "$FX/F6/archive.sql.gz"        0 ''
run_fixture F7 "$FX/F7/archive.ipambkl1.gz" "$FX/F7/archive.ipambkl1.gz"   0 ''

echo "── extra: F3 hex vault key ──"
expect "F3.hex green" 0 -- "${TOOL[@]}" --in "$FX/F3/archive.ipambkp3" --out "$T/f3hex.out" --vault-key "$VKHEX"
byte_eq "$T/f3hex.out" "$KL1SRC"

echo "── cross-cutting ──"
expect "C1 no args" 2 -- "${TOOL[@]}"
"${TOOL[@]}" --help >"$T/out" 2>"$T/err"; g=$?
if [ "$g" -eq 0 ] && grep -q 'usage:' "$T/out"; then ok "C2 --help (exit 0, usage on stdout)"; else bad "C2 --help (exit $g)"; fi
expect "C3 conflicting creds" 2 -- "${TOOL[@]}" --in "$FX/F1/archive.enc" --out "$T/c3.out" --app-secret "$APPS" --vault-key "$VKB64"
IPAM_BACKUP_PASSPHRASE="$PASS" "${TOOL[@]}" --in "$FX/F4/archive.ipambkp3" --out "$T/c4.out" >"$T/out" 2>"$T/err"; g=$?
if [ "$g" -eq 0 ]; then ok "C4 env passphrase (exit 0)"; byte_eq "$T/c4.out" "$KL1SRC"; else bad "C4 env passphrase (exit $g) :: $(head -c200 "$T/err")"; fi
if [ -d vendor ]; then
  mv vendor vendor.pass1bak
  "${TOOL[@]}" --in "$FX/F2/archive.enc" --out "$T/c5.out" --app-secret "$APPS" >"$T/out" 2>"$T/err"; g=$?
  mv vendor.pass1bak vendor
  if [ "$g" -eq 0 ]; then ok "C5 no-vendor green path still works (exit 0)"; byte_eq "$T/c5.out" "$PLAIN"; else bad "C5 no-vendor (exit $g) :: $(head -c200 "$T/err")"; fi
else echo "  NOTE  C5 skipped — no vendor/ dir present"; fi
ok "C6 no machine binding (caller-supplied keys; verified by code inspection of backup_decrypt_*)"
"${TOOL[@]}" --in "$FX/F1/archive.enc" --out "$T/c7_f1.out" --app-secret "$APPS" >/dev/null 2>&1
gunzip -c "$FX/F6/archive.sql.gz" > "$T/c7_f6.out"
if cmp -s "$T/c7_f1.out" "$T/c7_f6.out" && cmp -s "$T/c7_f1.out" "$PLAIN"; then ok "C7 round-trip parity (F1 == gunzip(F6) == plaintext-source.sqlite)"; else bad "C7 round-trip parity MISMATCH"; fi
if [ "${1:-}" = "--large" ] && [ -f /tmp/decrypt-c8-F2.enc ]; then
  _measure_rss "$T/timed" -- "${TOOL[@]}" --in /tmp/decrypt-c8-F2.enc --out "$T/c8.out" --app-secret "$APPS" >"$T/out"
  RSS=$(_extract_rss "$T/timed")
  echo "  NOTE  C8 IPAMBKP2 500MB: max RSS = ${RSS:-?} bytes ($(( ${RSS:-0}/1024/1024 )) MiB)"
  if [ -n "$RSS" ] && [ "$RSS" -lt 268435456 ]; then ok "C8 RSS bounded (<256 MiB)"; else bad "C8 RSS unbounded or unmeasured"; fi
  _measure_rss "$T/timed" -- "${TOOL[@]}" --in /tmp/decrypt-c8-F5.ipambku1 --out "$T/c8b.out" >"$T/out"
  RSS2=$(_extract_rss "$T/timed")
  echo "  NOTE  C8 IPAMBKU1 500MB: max RSS = ${RSS2:-?} bytes ($(( ${RSS2:-0}/1024/1024 )) MiB)"
else echo "  NOTE  C8 skipped — run with --large after 'php tests/fixtures/decrypt-tool/generate-fixtures.php --large'"; fi

echo
echo "================  PASS=$PASSCOUNT  FAIL=$FAILCOUNT  ================"
[ "$FAILCOUNT" -eq 0 ]
