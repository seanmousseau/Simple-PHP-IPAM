<?php /** @var array<string,mixed> $cfg */
$cfg = $cfg ?? [];
$h  = e(to_str($cfg['host'] ?? ''));
$p  = to_int($cfg['port'] ?? 22);
$u  = e(to_str($cfg['username'] ?? ''));
$rp = e(to_str($cfg['remote_path'] ?? ''));
?>
<label>Host <input type="text" name="sftp_host" value="<?= $h ?>" required></label>
<label>Port <input type="number" name="sftp_port" value="<?= $p ?>" min="1" max="65535"></label>
<label>Username <input type="text" name="sftp_username" value="<?= $u ?>" required></label>
<label>Password <input type="password" name="sftp_password" autocomplete="new-password" placeholder="<?= isset($cfg['password']) ? '(unchanged)' : '(blank if using key)' ?>"></label>
<?php if (isset($cfg['password'])): ?>
<label class="muted"><input type="checkbox" name="sftp_password__clear" value="1"> Clear stored password</label>
<?php endif; ?>
<label>Private key (PEM) <textarea name="sftp_private_key" rows="6" placeholder="<?= isset($cfg['private_key']) ? '(unchanged — paste here to replace)' : '-----BEGIN OPENSSH PRIVATE KEY-----' ?>"></textarea></label>
<?php if (isset($cfg['private_key'])): ?>
<label class="muted"><input type="checkbox" name="sftp_private_key__clear" value="1"> Clear stored private key</label>
<?php endif; ?>
<label>Remote path <input type="text" name="sftp_remote_path" value="<?= $rp ?>" placeholder="/backups/ipam/" required></label>
<label>Host fingerprint (SHA256, optional) <input type="text" name="sftp_fingerprint" value="<?= e(to_str($cfg['fingerprint'] ?? '')) ?>"></label>
