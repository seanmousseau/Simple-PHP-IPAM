#!/usr/bin/env bash
#
# Memory MCP audit (ADR-006).
#
# Sprint-kickoff sanity check on the agent session-state graph. Three audits:
#
#   1. Orphan bug entities — `project:simple-php-ipam:bug:*` with no outgoing
#      relations. Per ADR-006 every bug must link to at least one hotspot,
#      release, or regression chain; an orphan is a sign the graph wasn't
#      updated when work landed.
#   2. Stale release close-outs — every `project:simple-php-ipam:release:v*`
#      entity must carry an observation containing the substring "RELEASED".
#      Missing means the release was opened but never closed out.
#   3. Observation-vs-flat-file drift — out of scope for v1; printed as a
#      manual reminder pointing at ADR-006 § Implications #2.
#
# Data source: the daily ~/OneDrive/Development/backups/claude-memory-*.json
# dump produced by the launchd-driven ~/bin/backup-claude-memory.sh agent.
# The file is **NDJSON** — one entity- or relation-record per line, each with
# a `type` discriminator. The MCP server itself isn't directly reachable
# from a normal shell, hence the backup-as-source approach.
#
# Exit codes:
#   0  audit clean (or backup absent — fallback checklist printed to stderr).
#   1  one or more audit failures detected in checks 1 or 2.
#   2  prerequisite missing (jq).
#
# Refs: docs/internal/architecture-decisions/006-memory-mcp-discipline.md
#
set -euo pipefail

BACKUP_DIR="${MEMORY_AUDIT_BACKUP_DIR:-$HOME/OneDrive/Development/backups}"
PROJECT_PREFIX="project:simple-php-ipam"

print_fallback_checklist() {
    cat >&2 <<'EOF'
memory-audit: no Memory MCP backup found.

Fall back to the ADR-006 manual checklist:

  1. Orphan bug entities
     - In Claude Code, run:
         search_nodes("project:simple-php-ipam:bug:")
     - For each result, eyeball outgoing relations. Any bug with zero
       relations is an orphan — link it to a hotspot, release, or
       regression chain, or delete it if obsolete.

  2. Stale release close-outs
     - In Claude Code, run:
         search_nodes("project:simple-php-ipam:release:v")
     - Every release entity should have an observation containing the
       word RELEASED (with tag, merge commit, and bundle SHA256). Add
       one for any release missing it.

  3. Observation-vs-flat-file drift
     - Review every "we always X" rule that appears in your working
       memory and confirm a matching paragraph exists in
       docs/internal/*.md. If only the graph remembers it, write it
       down. See ADR-006 § Implications #2.

Once you have a backup file at $HOME/OneDrive/Development/backups/, or
~/bin/backup-claude-memory.sh runs successfully, re-run this script for
the automated version of checks 1 and 2.
EOF
}

if ! command -v jq >/dev/null 2>&1; then
    echo "memory-audit: jq not found on PATH (brew install jq)" >&2
    exit 2
fi

# Newest backup. YYYYMMDD-HHMMSSZ stamps sort lexicographically.
backup=""
if [[ -d "$BACKUP_DIR" ]]; then
    # shellcheck disable=SC2012  # ls is fine here — filenames are controlled
    backup=$(ls -1 "$BACKUP_DIR"/claude-memory-*.json 2>/dev/null | sort | tail -1 || true)
fi

if [[ -z "$backup" ]]; then
    print_fallback_checklist
    exit 0
fi

if [[ ! -r "$backup" ]]; then
    echo "memory-audit: newest backup $backup is not readable" >&2
    print_fallback_checklist
    exit 0
fi

echo "# Memory MCP audit"
echo
echo "- Backup: \`$backup\`"
echo "- Generated: $(date -u +'%Y-%m-%d %H:%M:%S UTC')"
echo "- Project scope: \`$PROJECT_PREFIX\`"
echo

# ---------------------------------------------------------------------------
# Check 1: orphan bug entities.
# Bug entities (name starts with $PROJECT_PREFIX:bug:) whose name never
# appears as a `from` in any relation record.
# ---------------------------------------------------------------------------
echo "## Check 1 — Orphan bug entities"
echo

if ! orphans=$(jq -rs --arg p "$PROJECT_PREFIX:bug:" '
  ([.[] | select(.type == "entity") | .name // empty
          | select(startswith($p))] | unique) as $bugs
  | ([.[] | select(.type == "relation") | .from // empty] | unique) as $froms
  | ([.[] | select(.type == "entity")
          | select((.observations // []) | join(" ") | test("no related bugs"; "i"))
          | .name // empty] | unique) as $explicit
  | ($bugs - $froms - $explicit) | .[]
' "$backup"); then
    echo "memory-audit: jq failed parsing $backup (check 1) — cannot audit" >&2
    exit 1
fi

rc1=0
if [[ -z "$orphans" ]]; then
    echo "OK — no orphan bug entities."
else
    echo "FAIL — bug entities with no outgoing relations:"
    echo
    echo "$orphans" | sed 's/^/  - /'
    rc1=1
fi
echo

# ---------------------------------------------------------------------------
# Check 2: stale release close-outs.
# Release entities (name starts with $PROJECT_PREFIX:release:) whose
# observations do NOT contain the substring "RELEASED".
# ---------------------------------------------------------------------------
echo "## Check 2 — Release close-outs"
echo

if ! stale=$(jq -rs --arg p "$PROJECT_PREFIX:release:" '
  .[]
  | select(.type == "entity")
  | select((.name // "") | startswith($p))
  | select(((.observations // []) | join(" ")) | contains("RELEASED") | not)
  | .name
' "$backup"); then
    echo "memory-audit: jq failed parsing $backup (check 2) — cannot audit" >&2
    exit 1
fi

rc2=0
if [[ -z "$stale" ]]; then
    echo "OK — every release entity carries a RELEASED observation."
else
    echo "FAIL — release entities missing a RELEASED observation:"
    echo
    echo "$stale" | sed 's/^/  - /'
    rc2=1
fi
echo

# ---------------------------------------------------------------------------
# Check 3: observation-vs-flat-file drift (manual).
# ---------------------------------------------------------------------------
echo "## Check 3 — Observation-vs-flat-file drift (manual)"
echo
echo "Out of scope for this script. Per ADR-006 § Implications #2, review"
echo "every \"we always X\" rule referenced in working memory and confirm a"
echo "matching paragraph exists in \`docs/internal/*.md\`. If a rule only"
echo "lives in the graph, write it down before relying on it."
echo

if [[ $rc1 -eq 0 && $rc2 -eq 0 ]]; then
    echo "_Result: audit clean._"
    exit 0
fi

echo "_Result: audit FAILED — see check sections above._"
exit 1
