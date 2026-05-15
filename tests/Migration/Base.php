<?php
declare(strict_types=1);

namespace Tests\Migration;

use PHPUnit\Framework\TestCase;
use PDO;

/**
 * v3.29.0 #902 — shared base for the split MigrationTest suites.
 *
 * Extracted verbatim from the original tests/MigrationTest.php mega-file
 * so the per-cluster suites can share makeDb() and makePreVrfDb() without
 * duplicating ~160 lines of fixture-builder. Behaviour and row counts MUST
 * stay byte-equivalent — the assertion totals in the split are compared
 * against the pre-split baseline.
 */
abstract class Base extends TestCase
{
    /**
     * Open a fresh in-memory SQLite PDO connection with the same settings used
     * by ipam_db(): ERRMODE_EXCEPTION, FETCH_ASSOC, and foreign_keys = ON.
     */
    protected function makeDb(): PDO
    {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("PRAGMA foreign_keys = ON");
        return $db;
    }

    /**
     * Build a database that matches the v2.0.0 schema state immediately before
     * the 2.1.0-vrfs migration runs. See original MigrationTest.php for full
     * rationale; this is preserved verbatim from the pre-split file.
     */
    protected function makePreVrfDb(): PDO
    {
        $db = $this->makeDb();

        $db->exec("
            CREATE TABLE schema_migrations (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                version    TEXT    NOT NULL UNIQUE,
                applied_at TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE sites (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                name        TEXT    NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                parent_id   INTEGER REFERENCES sites(id) ON DELETE SET NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE vlans (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                vlan_id     INTEGER NOT NULL,
                name        TEXT    NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                site_id     INTEGER REFERENCES sites(id) ON DELETE SET NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE contacts (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL,
                email      TEXT NOT NULL DEFAULT '',
                phone      TEXT NOT NULL DEFAULT '',
                org        TEXT NOT NULL DEFAULT '',
                note       TEXT NOT NULL DEFAULT '',
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE subnets (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                cidr        TEXT    NOT NULL UNIQUE,
                ip_version  INTEGER NOT NULL,
                network     TEXT    NOT NULL,
                network_bin BLOB    NOT NULL,
                prefix      INTEGER NOT NULL,
                description TEXT    NOT NULL DEFAULT '',
                site_id     INTEGER,
                vlan_id     INTEGER,
                vlan_fk     INTEGER REFERENCES vlans(id) ON DELETE SET NULL,
                created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at  TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $db->exec("
            CREATE TABLE tags (
                id         INTEGER PRIMARY KEY AUTOINCREMENT,
                name       TEXT NOT NULL UNIQUE,
                colour     TEXT NOT NULL DEFAULT '#6c757d',
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("
            CREATE TABLE subnet_tags (
                subnet_id INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                tag_id    INTEGER NOT NULL REFERENCES tags(id)    ON DELETE CASCADE,
                PRIMARY KEY (subnet_id, tag_id)
            )
        ");
        $db->exec("
            CREATE TABLE alert_state (
                subnet_id       INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                level           TEXT    NOT NULL,
                last_alerted_at TEXT    NOT NULL,
                PRIMARY KEY (subnet_id, level)
            )
        ");

        $db->exec("
            CREATE TABLE addresses (
                id               INTEGER PRIMARY KEY AUTOINCREMENT,
                subnet_id        INTEGER NOT NULL REFERENCES subnets(id) ON DELETE CASCADE,
                ip               TEXT    NOT NULL,
                ip_bin           BLOB    NOT NULL,
                hostname         TEXT    NOT NULL DEFAULT '',
                owner            TEXT    NOT NULL DEFAULT '',
                owner_contact_id INTEGER REFERENCES contacts(id) ON DELETE SET NULL,
                note             TEXT    NOT NULL DEFAULT '',
                grp              TEXT    NOT NULL DEFAULT '',
                status           TEXT    NOT NULL DEFAULT 'used',
                mac              TEXT    NOT NULL DEFAULT '',
                expires_at       TEXT,
                created_at       TEXT    NOT NULL DEFAULT (datetime('now')),
                updated_at       TEXT    NOT NULL DEFAULT (datetime('now'))
            )
        ");
        $db->exec("
            CREATE TABLE address_tags (
                address_id INTEGER NOT NULL REFERENCES addresses(id) ON DELETE CASCADE,
                tag_id     INTEGER NOT NULL REFERENCES tags(id)      ON DELETE CASCADE,
                PRIMARY KEY (address_id, tag_id)
            )
        ");

        $db->exec("
            CREATE TABLE audit_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                action      TEXT NOT NULL,
                entity_type TEXT NOT NULL DEFAULT '',
                entity_id   INTEGER,
                user_id     INTEGER,
                username    TEXT NOT NULL DEFAULT '',
                ip          TEXT NOT NULL DEFAULT '',
                user_agent  TEXT NOT NULL DEFAULT '',
                details     TEXT NOT NULL DEFAULT '',
                created_at  TEXT NOT NULL DEFAULT (datetime('now'))
            )
        ");

        $alreadyApplied = [
            '0.3', '0.7', '0.9', '0.11', '0.12', '0.13', '0.14',
            '1.4', '1.9', '1.11', '1.12', '1.13', '1.19.0',
            '2.0.0-alert-state', '2.0.0-site-hierarchy', '2.0.0-tags', '2.0.0-vlans',
            '2.1.0-contacts',
        ];
        $st = $db->prepare("INSERT INTO schema_migrations (version) VALUES (?)");
        foreach ($alreadyApplied as $v) {
            $st->execute([$v]);
        }

        $ins = $db->prepare("
            INSERT INTO subnets (cidr, ip_version, network, network_bin, prefix, description)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        $ins->execute(['10.0.0.0/24', 4, '10.0.0.0', inet_pton('10.0.0.0'), 24, 'test subnet 1']);
        $ins->execute(['10.1.0.0/24', 4, '10.1.0.0', inet_pton('10.1.0.0'), 24, 'test subnet 2']);

        $ins = $db->prepare("
            INSERT INTO addresses (subnet_id, ip, ip_bin, hostname, status)
            VALUES (?, ?, ?, ?, 'used')
        ");
        foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
            $ins->execute([1, $ip, inet_pton($ip), "host-$ip"]);
        }
        foreach (['10.1.0.1', '10.1.0.2'] as $ip) {
            $ins->execute([2, $ip, inet_pton($ip), "host-$ip"]);
        }

        return $db;
    }
}
