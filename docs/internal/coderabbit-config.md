# CodeRabbit configuration

> How CodeRabbit's per-repo `.coderabbit.yaml` interacts with the org-level config, with a focus on the inheritance gotchas that have caused recurring confusion. Read this before changing CR settings or trying to debug a CR check that "should be inheriting from the org but isn't."

## Two-level configuration

CR resolves settings in this order:
1. **Repo-level `.coderabbit.yaml`** at the repository root (this project: `/.coderabbit.yaml`)
2. **Org-level config** managed in the CodeRabbit dashboard (sean's org settings)
3. **CR's hardcoded platform defaults**

The merge semantics are NOT "deep merge of org over repo." Per-key inheritance varies by feature class:

| Feature class | Inheritance behaviour |
|---|---|
| Stable settings (`reviews.profile`, `reviews.auto_review`, title check, etc.) | Inherit normally — repo overrides where defined, org fills in everything else |
| Early-access settings (most `pre_merge_checks` subkeys including `docstrings`) | **Only inherit if the repo also sets `early_access: true`** |
| Free-form text (`tone_instructions`, `language`) | Repo wins where defined, org otherwise |

The early-access gating is the most common gotcha: an org-level setting like `pre_merge_checks.docstrings.threshold: 40` will silently fall back to CR's hardcoded `80%` default unless the repo's `.coderabbit.yaml` opts into early-access by declaring `early_access: true` at the top level.

## Current repo configuration

This repo's `.coderabbit.yaml` opts into early-access AND pins the docstrings threshold explicitly as belt-and-suspenders:

```yaml
early_access: true

reviews:
  ...
  pre_merge_checks:
    docstrings:
      threshold: 40
```

Both lines are intentional. The `early_access` flag unlocks org-config inheritance for the whole pre-merge-checks subtree; the explicit `threshold: 40` makes the override resilient to future CR inheritance-behaviour changes. Don't remove either without the other.

## Symptoms that point at this gotcha

- CR PR review header says "Docstring Coverage Required threshold is 80.00%" even though the org config says `40`.
- Conventional-commit title check works (it inherits from org) but docstring threshold doesn't (it requires early-access opt-in).
- A new repo added to the org has the same problem until its `.coderabbit.yaml` adds `early_access: true`.

If you see a CR check using a different threshold than the org-level setting, the first thing to check is whether `early_access: true` is present in the repo config. The second thing is to explicitly pin the affected setting in the repo config — that's bulletproof regardless of inheritance behaviour.

## Org-level settings that DO inherit reliably

These work regardless of `early_access` flag because they're stable:

- `reviews.auto_review.labels` and `base_branches`
- `reviews.pre_merge_checks.title.requirements` (Conventional Commits requirement)
- `reviews.sequence_diagrams`, `reviews.poem`, `reviews.suggested_labels`
- `reviews.finishing_touches.docstrings.enabled`
- `chat.allow_non_org_members`

If you change one of these in the org config, the change applies to this repo automatically — no repo PR needed.

## Org-level settings that need the repo opt-in

These are early-access and need `early_access: true` in the repo config:

- `reviews.pre_merge_checks.docstrings.threshold`
- Any new pre-merge check that lands as early-access

When CR promotes a setting from early-access to stable, the repo opt-in becomes redundant for that key but doesn't hurt to keep. The list above will become smaller over time as CR matures features out of early-access.

## Verifying the effective config

CR exposes the effective merged configuration in the PR review's "Effective configuration" panel (collapsed by default in the walkthrough comment). Expand it to see exactly what CR is using on a specific PR — that's the authoritative answer when troubleshooting.

If the panel shows your org-level value, the repo is inheriting correctly. If it shows CR's hardcoded default, the early-access opt-in is likely missing.

## When to update this doc

Update this doc when:

- CR ships a new pre-merge check (early-access or stable) — note which class.
- An org-level setting that you expected to inherit doesn't, and the root cause is something other than the early-access flag.
- CR changes the repo-vs-org merge semantics in a way that affects this project.
