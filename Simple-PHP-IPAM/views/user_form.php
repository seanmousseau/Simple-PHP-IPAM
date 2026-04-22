<?php
declare(strict_types=1);
/**
 * Partial: Create User form (used in the global drawer on users.php).
 *
 * Props injected via extract():
 *   $formData   array<string,mixed>  — repopulated field values on validation error
 *   $config     IpamConfig           — app config (needed for oidc_enabled check)
 */
/** @var array<string,mixed> $formData */
/** @var IpamConfig $config */
?>
<form method="post" action="users.php" id="create-user-form">
  <input type="hidden" name="csrf"   value="<?= e(csrf_token()) ?>">
  <input type="hidden" name="action" value="create">
  <div class="row">
    <label>Username<br><input name="username" required value="<?= e(to_str($formData['username'])) ?>"></label>
    <label>Full name<br><input name="name" placeholder="Jane Smith" maxlength="255" value="<?= e(to_str($formData['name'])) ?>"></label>
    <label>Email<br><input type="email" name="email" placeholder="jane@example.com" maxlength="255" value="<?= e(to_str($formData['email'])) ?>"></label>
    <label id="pw-field">Password<br><input type="password" name="password" id="create-pw-input"></label>
    <?php if (oidc_enabled($config)): ?>
    <label id="sub-field" class="hidden">Subject (sub)<br>
      <input name="oidc_sub" id="create-sub-input" placeholder="IdP sub claim (optional)" value="<?= e(to_str($formData['oidc_sub'])) ?>">
    </label>
    <?php endif; ?>
    <label>Role<br>
      <select name="role">
        <?php foreach (['readonly', 'netops', 'admin'] as $r): ?>
          <option value="<?= e($r) ?>"<?= $formData['role'] === $r ? ' selected' : '' ?>><?= e($r) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <?php if (oidc_enabled($config)): ?>
    <label class="flex-self-end pb-6">
      <input type="checkbox" name="sso_only" id="sso-only-toggle" value="1"<?= $formData['sso_only'] ? ' checked' : '' ?>>
      SSO-only account
    </label>
    <?php endif; ?>
    <button type="submit">Create</button>
  </div>
</form>
