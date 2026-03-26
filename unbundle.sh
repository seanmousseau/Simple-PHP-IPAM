#!/usr/bin/env bash
set -euo pipefail

# Usage:
#   ./unbundle.sh [bundle_or_dir] [output_dir]
#
# Examples:
#   ./unbundle.sh bundle.txt ./ipam
#   ./unbundle.sh ./bundles ./ipam
#   ./unbundle.sh . ./ipam
#
# Behavior:
# - If the first argument is a file, extract that one bundle.
# - If the first argument is a directory (or omitted), automatically find bundle-like files:
#     bundle.txt
#     bundle1.txt
#     bundle2.txt
#     bundle-foo.txt
#     anything matching: bundle*.txt
# - Files are processed in version-sort order where available.

INPUT_PATH="${1:-.}"
OUT_DIR="${2:-.}"

mkdir -p "${OUT_DIR}"

# Build bundle file list without mapfile
BUNDLES=""

if [[ -f "${INPUT_PATH}" ]]; then
  BUNDLES="${INPUT_PATH}"
elif [[ -d "${INPUT_PATH}" ]]; then
  BUNDLES="$(find "${INPUT_PATH}" -maxdepth 1 -type f -name 'bundle*.txt' | sort -V 2>/dev/null || find "${INPUT_PATH}" -maxdepth 1 -type f -name 'bundle*.txt' | sort)"
else
  echo "Input path not found: ${INPUT_PATH}" >&2
  exit 1
fi

if [[ -z "${BUNDLES}" ]]; then
  echo "No bundle*.txt files found in: ${INPUT_PATH}" >&2
  exit 1
fi

echo "Found bundle file(s):" >&2
printf '%s\n' "${BUNDLES}" | sed 's/^/  /' >&2

printf '%s\n' "${BUNDLES}" | while IFS= read -r BUNDLE_FILE; do
  [[ -n "${BUNDLE_FILE}" ]] || continue
  echo "Processing: ${BUNDLE_FILE}" >&2

  awk -v OUT_DIR="${OUT_DIR}" '
  function ltrim(s) { sub(/^[ \t\r\n]+/, "", s); return s }
  function rtrim(s) { sub(/[ \t\r\n]+$/, "", s); return s }
  function trim(s)  { return rtrim(ltrim(s)) }

  BEGIN {
    writing = 0
    out = ""
  }

  /^===== FILE: / {
    if (writing == 1) {
      printf("Error: encountered new FILE marker before END marker (currently writing %s)\n", out) > "/dev/stderr"
      exit 2
    }

    line = $0
    sub(/^===== FILE: /, "", line)
    sub(/ =====$/, "", line)
    rel = trim(line)

    if (rel == "") {
      print "Error: empty path in FILE marker" > "/dev/stderr"
      exit 2
    }

    if (rel ~ /^\//) {
      printf("Error: absolute path not allowed: %s\n", rel) > "/dev/stderr"
      exit 2
    }
    if (rel ~ /(^|\/)\.\.(\/|$)/) {
      printf("Error: path traversal not allowed: %s\n", rel) > "/dev/stderr"
      exit 2
    }

    out = OUT_DIR "/" rel

    dir = out
    sub(/\/[^\/]+$/, "", dir)
    if (dir == out) dir = OUT_DIR

    system("mkdir -p \"" dir "\"")
    system(": > \"" out "\"")

    writing = 1
    next
  }

  /^===== END FILE: / {
    if (writing == 0) {
      print "Error: END marker encountered while not writing" > "/dev/stderr"
      exit 2
    }
    writing = 0
    out = ""
    next
  }

  {
    if (writing == 1) {
      print $0 >> out
    }
  }

  END {
    if (writing == 1) {
      printf("Error: EOF reached before END marker for %s\n", out) > "/dev/stderr"
      exit 2
    }
  }
  ' "${BUNDLE_FILE}"
done

mkdir -p "${OUT_DIR}/data"

echo "Bundle extraction complete into: ${OUT_DIR}" >&2

cat <<'NOTE'

Next steps:
  1) Ensure the output directory is served by Apache/LiteSpeed.
  2) Ensure the "data/" directory is writable by the web server user:
       chmod 700 data
       chown -R www-data:www-data data   # adjust user/group as needed
  3) Edit config.php bootstrap admin password and proxy_trust as needed.
  4) Visit https://yourhost/ (first run will create the SQLite DB).

NOTE
