<?php
declare(strict_types=1);
/**
 * restore_dsn.php — DSN parsing helper for restore.php.
 *
 * Extracted from restore.php so the parser is unit-testable without tripping
 * the CLI-only guard at the top of restore.php. (#1177)
 */

if (!function_exists('ipam_restore_resolve_db_conn')) {
    /**
     * Resolve mysql/pgsql connection parameters for the CLI restore script.
     * Parses $gConf['db_dsn'] (PDO-style: 'mysql:host=...;port=...;dbname=...'
     * or 'pgsql:host=...;port=...;dbname=...') when present; falls back to the
     * discrete db_host/db_port/db_name keys. (#1177)
     *
     * PDO DSN format is unquoted; ';' is a hard delimiter. The password is
     * NOT read from the DSN; it always comes from $gConf['db_pass']. Returns
     * an empty `driver` field when no DSN is set — informational only; both
     * callers select the mysql/pgsql client by $driver (the SQL-engine arg
     * to restore.php), not by this field.
     *
     * Unsupported DSN forms (caller signals via empty/wrong host): the
     * `unix_socket=` key — restore.php cannot pipe `mysql -h <socket>`. The
     * restore.php caller detects this and surfaces a clear error before
     * proc_open.
     *
     * `$targetDriver` is a hint from the caller (the SQL-engine restore.php
     * branch). When `db_dsn` is absent, the parsed `$driver` is empty and
     * the port/user defaults would otherwise fall to the mysql values even
     * on a pgsql install. Pass `'mysql'` / `'pgsql'` from the caller to
     * pick the right default. (#1177)
     *
     * @param array<string,mixed> $gConf
     * @param string $targetDriver  ''|'mysql'|'pgsql' — caller hint for defaults
     * @return array{driver:string,host:string,port:string,dbname:string,user:string,pass:string,unix_socket:string}
     */
    function ipam_restore_resolve_db_conn(array $gConf, string $targetDriver = ''): array
    {
        $dsn         = to_str($gConf['db_dsn'] ?? '');
        $driver      = '';
        $host        = '';
        $port        = '';
        $dbname      = '';
        $unixSocket  = '';

        if ($dsn !== '') {
            [$driver, $rest] = array_pad(explode(':', $dsn, 2), 2, '');
            foreach (explode(';', $rest) as $kv) {
                [$k, $v] = array_pad(explode('=', $kv, 2), 2, '');
                $k = trim($k);
                $v = trim($v);
                if ($k === 'host') {
                    $host = $v;
                } elseif ($k === 'port') {
                    $port = $v;
                } elseif ($k === 'dbname') {
                    $dbname = $v;
                } elseif ($k === 'unix_socket') {
                    $unixSocket = $v;
                }
            }
        }

        // Prefer the DSN-parsed driver; fall back to the caller hint so a
        // DSN-less install still picks the right port/user defaults.
        $effectiveDriver = $driver !== '' ? $driver : $targetDriver;

        if ($host === '') {
            $host = to_str($gConf['db_host'] ?? '127.0.0.1');
        }
        if ($port === '') {
            $port = to_str($gConf['db_port'] ?? ($effectiveDriver === 'pgsql' ? '5432' : '3306'));
        }
        if ($dbname === '') {
            $dbname = to_str($gConf['db_name'] ?? 'ipam');
        }

        return [
            'driver'      => $driver,
            'host'        => $host,
            'port'        => $port,
            'dbname'      => $dbname,
            'user'        => to_str($gConf['db_user'] ?? ($effectiveDriver === 'pgsql' ? 'postgres' : 'root')),
            'pass'        => to_str($gConf['db_pass'] ?? ''),
            'unix_socket' => $unixSocket,
        ];
    }
}
