<?php
declare(strict_types=1);

function ipam_migrations(): array
{
    return [
        '0.3' => function(PDO $db) {
            $cols = $db->query("PRAGMA table_info(subnets)")->fetchAll();
            $names = array_map(fn($c) => $c['name'], $cols);

            if (!in_array('network_bin', $names, true)) {
                $db->exec("ALTER TABLE subnets ADD COLUMN network_bin BLOB");
            }

            $st = $db->prepare("SELECT id, network FROM subnets WHERE network_bin IS NULL OR length(network_bin)=0");
            $st->execute();
            $rows = $st->fetchAll();

            $up = $db->prepare("UPDATE subnets SET network_bin = :b WHERE id = :id");
            foreach ($rows as $r) {
                $bin = @inet_pton((string)$r['network']);
                if ($bin === false) continue;
                $up->execute([':b' => $bin, ':id' => (int)$r['id']]);
            }

            $db->exec("CREATE INDEX IF NOT EXISTS idx_subnets_ver_prefix_netbin ON subnets(ip_version, prefix, network_bin)");
        },
    ];
}
