#!/bin/bash
set -euo pipefail

BUNDLE_FILE="${1:-}"
OUT_DIR="${2:-.}"

if [[ -z "${BUNDLE_FILE}" || ! -f "${BUNDLE_FILE}" ]]; then
  echo "Usage: $0 bundle.txt [output_dir]" >&2
  exit 1
fi

mkdir -p "${OUT_DIR}"

awk -v OUT_DIR="${OUT_DIR}" '
function ltrim(s) { sub(/^[ \t\r\n]+/, "", s); return s }
function rtrim(s) { sub(/[ \t\r\n]+$/, "", s); return s }
function trim(s)  { return rtrim(ltrim(s)) }

BEGIN { writing=0; out="" }

# Start marker
/^===== FILE: / {
  if (writing==1) { print "Error: nested FILE marker" > "/dev/stderr"; exit 2 }
  line=$0
  sub(/^===== FILE: /,"",line)
  sub(/ =====$/,"",line)
  rel=trim(line)

  if (rel=="") { print "Error: empty path" > "/dev/stderr"; exit 2 }
  if (rel ~ /^\//) { print "Error: absolute path not allowed: " rel > "/dev/stderr"; exit 2 }
  if (rel ~ /(^|\/)\.\.(\/|$)/) { print "Error: path traversal not allowed: " rel > "/dev/stderr"; exit 2 }

  out=OUT_DIR "/" rel
  dir=out
  sub(/\/[^\/]+$/,"",dir)
  if (dir==out) dir=OUT_DIR
  system("mkdir -p \"" dir "\"")
  system(": > \"" out "\"")
  writing=1
  next
}

# End marker
/^===== END FILE: / {
  if (writing==0) { print "Error: END without FILE" > "/dev/stderr"; exit 2 }
  writing=0
  out=""
  next
}

{
  if (writing==1) print $0 >> out
}

END {
  if (writing==1) { print "Error: EOF before END marker for " out > "/dev/stderr"; exit 2 }
}
' "${BUNDLE_FILE}"

echo "Bundle extracted into: ${OUT_DIR}"
mkdir -p "${OUT_DIR}/data"
