# Semgrep scan summary — 2026-05-10 EOD

**Repo state:** v3.27.7 merged + deployed (tag v3.27.7, dev HEAD eee9666 backup-arch docs)
**Scan target:** Simple-PHP-IPAM/
**Rules:** `.semgrep/rules.yml` (project's custom rules) + `--config=auto` (Semgrep Registry security rules)
**Total findings:** 38 (all WARNING severity)
**Scan errors:** 5

## By rule

| Rule | Count |
|---|---:|
| `taint-unsafe-echo-tag` | 26 |
| `tainted-filename` | 6 |
| `unlink-use` | 6 |

## Top files

| File | Findings |
|---|---:|
| `import_csv.php` | 11 |
| `unassigned.php` | 11 |
| `search.php` | 7 |
| `address_history.php` | 3 |
| `download_remote_backup.php` | 2 |
| `destination_edit_drawer.php` | 1 |
| `lib/backup_admin_history.php` | 1 |
| `run_backup_now.php` | 1 |
| `settings_reveal.php` | 1 |

## Findings by rule (with sites)

### `taint-unsafe-echo-tag` (26 sites)

**CWE:** CWE-79: Improper Neutralization of Input During Web Page Generation ('Cross-site Scripting')
**What it flags:** Found direct access to a PHP variable wihout HTML escaping inside an inline PHP statement setting data from `$_REQUEST[...]`. When untrusted input can be used to tamper with a web page rendering, it c

**Sites:**
- `address_history.php:168`
- `address_history.php:169`
- `address_history.php:170`
- `destination_edit_drawer.php:38`
- `download_remote_backup.php:74`
- `import_csv.php:596`
- `run_backup_now.php:29`
- `search.php:253`
- `search.php:261`
- `search.php:345`
- … +16 more

### `tainted-filename` (6 sites)

**CWE:** CWE-918: Server-Side Request Forgery (SSRF)
**What it flags:** File name based on user input risks server-side request forgery.

**Sites:**
- `download_remote_backup.php:115`
- `import_csv.php:454`
- `import_csv.php:614`
- `import_csv.php:722`
- `import_csv.php:1081`
- `import_csv.php:1112`

### `unlink-use` (6 sites)

**CWE:** CWE-22: Improper Limitation of a Pathname to a Restricted Directory ('Path Traversal')
**What it flags:** Using user input when deleting files with `unlink()` is potentially dangerous. A malicious actor could use this to modify or access files they have no right to.

**Sites:**
- `import_csv.php:23`
- `import_csv.php:24`
- `import_csv.php:25`
- `import_csv.php:1068`
- `import_csv.php:1069`
- `lib/backup_admin_history.php:295`


## Scan errors (rules that didn't complete)

- `Internal matching error`: Internal matching error when running javascript.crypto-js.cryptojs-weak-algorithm.cryptojs-weak-algorithm on Simple-PHP-IPAM/assets/app.js:
 An error occurred while invoking the Semgrep engine. Please
- `Internal matching error`: Internal matching error when running javascript.express.web.cors-default-config-express.cors-default-config-express on Simple-PHP-IPAM/assets/app.js:
 An error occurred while invoking the Semgrep engi
- `Internal matching error`: Internal matching error when running javascript.koa.web.cors-default-config-koa.cors-default-config-koa on Simple-PHP-IPAM/assets/app.js:
 An error occurred while invoking the Semgrep engine. Please h
- `Timeout`: Timeout when running php.lang.security.tainted-user-input-in-php-script.tainted-user-input-in-php-script on Simple-PHP-IPAM/subnets.php:
 
- `['PartialParsing', [{'path': 'Simple-PHP-IPAM/upgrade.sh', 'start': {'line': 120, 'col': 22, 'offset': 0}, 'end': {'line': 120, 'col': 37, 'offset': 15}}]]`: Syntax error at line Simple-PHP-IPAM/upgrade.sh:120:
 `y|yes) return 0` was unexpected

## Tomorrow's triage workflow

1. Read this file. Identify high-likelihood real findings vs false positives by glancing at the rule names.
2. For each interesting rule, jump to its first site and read the surrounding code.
3. Cross-check against `PASS-C-SUMMARY.md` (avoid re-flagging known items).
4. Pair with `code-reviewer-findings.md` (judgment-driven; will exist by then).
5. Bucket each finding into v3.27.9 / v3.28.0 / v3.28.x / backlog / FP.
6. Roll into the milestone reshuffle (item 2 of `2026-05-11_session-plan.md`).