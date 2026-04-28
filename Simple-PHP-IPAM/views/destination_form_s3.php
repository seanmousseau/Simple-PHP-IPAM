<?php /** @var array<string,mixed> $cfg */
$cfg = $cfg ?? [];
$end = e(to_str($cfg['endpoint'] ?? ''));
$reg = e(to_str($cfg['region'] ?? 'us-east-1'));
$buc = e(to_str($cfg['bucket'] ?? ''));
$pre = e(to_str($cfg['prefix'] ?? 'ipam/'));
$ak  = e(to_str($cfg['access_key'] ?? ''));
?>
<label>Endpoint URL <input type="url" name="s3_endpoint" value="<?= $end ?>" placeholder="https://s3.amazonaws.com" required></label>
<label>Region <input type="text" name="s3_region" value="<?= $reg ?>" required></label>
<label>Bucket <input type="text" name="s3_bucket" value="<?= $buc ?>" required></label>
<label>Prefix <input type="text" name="s3_prefix" value="<?= $pre ?>"></label>
<label>Access key ID <input type="text" name="s3_access_key" value="<?= $ak ?>" autocomplete="off" required></label>
<label>Secret access key <input type="password" name="s3_secret_key" autocomplete="new-password"
   <?= isset($cfg['secret_key']) ? 'placeholder="(unchanged)"' : 'required placeholder="required"' ?>></label>
