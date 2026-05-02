# Audit log action reference

> Canonical list of every `audit()` action string used in the codebase. The naming convention is the load-bearing rule (kept in `CLAUDE.md`); this is the lookup table.

Call signature: `audit(PDO $db, string $action, string $entityType, ?int $entityId, string $details)`. The `$details` field is a JSON-encoded snapshot of the change.

**Convention:** `<entity>.<verb>` — lowercase, dot-separated, verb is one of `create`/`update`/`delete`/`toggle_active`/`set_role`/`reset_password`/etc. Never invent new verbs casually; reuse the existing vocabulary so log queries (`WHERE action LIKE 'subnet.%'`) stay consistent.

## Auth

```
auth.login              auth.login_failed       auth.login_blocked
auth.oidc_login         auth.oidc_provision     auth.oidc_link       auth.oidc_failed
auth.mfa_method_switch  auth.mfa_preferred_set
auth.totp_login         auth.email_otp_login    auth.passkey_challenge
```

## Core entities

```
subnet.create   subnet.update   subnet.delete
address.create  address.update  address.delete
site.create     site.update     site.delete
vlan.create     vlan.update     vlan.delete
vrf.create      vrf.update      vrf.delete
contact.create  contact.update  contact.delete
tag.create      tag.update      tag.delete
```

## Users & access

```
user.create         user.delete         user.toggle_active
user.set_role       user.reset_password user.update_profile
user.oidc_link      user.oidc_unlink

apikey.create       apikey.deactivate   apikey.activate     apikey.delete
```

## Custom fields

```
custom_field.create  custom_field.update  custom_field.delete  custom_field.reorder
```

## DHCP & address operations

```
dhcp_pool.reserve   dhcp_pool.clear
address.arp_import
```

## Bulk import/export

```
db.export   db.import   db.import_failed
export.*    import.*
```

The `export.*` and `import.*` wildcards cover per-entity bulk operations (`export.subnets`, `import.addresses`, etc.) — use the format `<scope>.<entity>` when adding new ones.

## Scanner

```
scan.run                 scan.schedule_create
scan.schedule_update     scan.schedule_delete
```

## Settings

```
setting.update
```

Single action covers every setting change. The `$details` JSON contains old and new value (sensitive values are masked — see `adding-a-setting.md`).

---

## Adding a new action

1. Match the existing `<entity>.<verb>` shape. Add to this table when the PR lands.
2. Use one of the existing verbs (`create`/`update`/`delete`/`toggle_active`/etc.) unless the action is genuinely novel.
3. The `$details` field should be a JSON string with enough context to reconstruct what happened — for `update` actions, include both old and new values where it adds value.
4. For sensitive values (credentials, keys), mask in `$details` before encoding.

## Cross-references

- `CLAUDE.md` → "Audit logging" — naming convention.
- `adding-an-api-endpoint.md` step 5 — when API write handlers must emit audit entries.
- `Simple-PHP-IPAM/lib.php` `audit()` — implementation.
