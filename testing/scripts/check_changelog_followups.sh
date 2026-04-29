#!/usr/bin/env bash
#
# CHANGELOG follow-up lint.
#
# Walks the most-recent `## [X.Y.Z]` section of CHANGELOG.md and refuses any
# bullet that contains "follow-up" / "deferred" / "pending" / etc. without
# also referencing a tracking issue (`#NNN`).
#
# Surfaced as #785 after a v3.17.0 CHANGELOG bullet shipped saying
# "MySQL/PostgreSQL backup pending follow-up" with NO tracking issue. The
# deferral never made it onto a roadmap; an operator hit it in production
# three releases later. This lint exists to make that pattern impossible.
#
# Add a Known limitations subsection at the top of the new release entry
# (with a tracking issue) instead of burying deferral language mid-bullet.
#
set -euo pipefail

CHANGELOG="${1:-CHANGELOG.md}"

if [ ! -f "$CHANGELOG" ]; then
  echo "check_changelog_followups: $CHANGELOG not found" >&2
  exit 2
fi

# Extract the FIRST `## [X.Y.Z] - DATE` section (the one being shipped).
section=$(awk '
  /^## \[/ { c++ }
  c == 1   { print }
  c == 2   { exit }
' "$CHANGELOG")

if [ -z "$section" ]; then
  echo "check_changelog_followups: no `## [X.Y.Z]` section found in $CHANGELOG" >&2
  exit 2
fi

# Phrases that indicate deferred / acknowledged-incomplete work. Any of these
# in a release entry needs a tracking issue (`#NNN`) on the same line, OR the
# entire item should live under a "Known limitations" subsection (which is
# also flagged below to prompt a tracking-issue check during review).
patterns='follow-?up|deferred|pending|to be added|coming soon|will be added|future release|tracked separately|in a (future|later)'

# Lines matching one of the deferral patterns, without an #NNN issue ref on
# the same line. Allow lines under "### Known limitations" only if they
# contain `#NNN` — that section EXPECTS deferral language but each item must
# still be tied to an issue.
unreferenced=$(printf '%s\n' "$section" \
  | grep -niE "$patterns" \
  | grep -vE '#[0-9]+' \
  || true)

if [ -z "$unreferenced" ]; then
  echo "check_changelog_followups: clean ($CHANGELOG)"
  exit 0
fi

echo
echo "❌ CHANGELOG.md has deferral language without a tracking issue (#NNN):"
echo
printf '%s\n' "$unreferenced" | sed 's/^/    /'
echo
echo "Each deferral MUST reference a GitHub issue, or the item belongs in a"
echo "### Known limitations subsection at the top of the release entry."
echo "See docs/internal/release-workflow.md and issue #785 for the convention."
exit 1
