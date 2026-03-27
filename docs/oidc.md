# OIDC Authentication

Simple-PHP-IPAM supports single sign-on via OpenID Connect (OIDC) Authorization Code + PKCE flow. Any compliant IdP works: Google, Microsoft Entra ID (Azure AD), Okta, Keycloak, Auth0, Authentik, Dex, and others.

OIDC is implemented in pure PHP using only the built-in `openssl` extension — no Composer packages required.

## Contents

- [How it works](#how-it-works)
- [Prerequisites](#prerequisites)
- [Configuration](#configuration)
- [IdP setup examples](#idp-setup-examples)
- [User provisioning and linking](#user-provisioning-and-linking)
- [Disabling local login](#disabling-local-login)
- [Troubleshooting](#troubleshooting)

---

## How it works

1. User clicks **Sign in with \<display\_name\>** on the login page
2. Browser is redirected to the IdP with a PKCE code challenge, state, and nonce
3. User authenticates at the IdP
4. IdP redirects back to `oidc_callback.php` with an authorization code
5. The callback exchanges the code for an ID token (verifying the PKCE code verifier)
6. The ID token signature is verified against the IdP's JWKS (RS256/RS384/RS512)
7. The `sub` claim is matched to a local user account; if `auto_provision` is enabled a new account is created if none exists
8. The user is logged in with the same session mechanism as local auth

The discovery document and JWKS are cached in `data/tmp/` for one hour. A single automatic JWKS cache-bust is attempted if signature verification fails, to handle in-flight key rotation.

---

## Prerequisites

- PHP 8.2+ with `openssl` extension (standard on most hosts)
- HTTPS — OIDC callbacks must be served over HTTPS
- `allow_url_fopen = On` in `php.ini` (used for discovery and JWKS fetches)
- An OIDC client registered with your IdP (see [IdP setup examples](#idp-setup-examples))

---

## Configuration

Add the following to your `config.php`:

```php
'oidc' => [
    'enabled'             => true,
    'display_name'        => 'Okta',          // Label on the login button
    'client_id'           => 'your-client-id',
    'client_secret'       => 'your-client-secret',
    'discovery_url'       => 'https://your-org.okta.com/oauth2/default',
    'redirect_uri'        => 'https://ipam.example.com/oidc_callback.php',
    'scopes'              => 'openid email profile',
    'auto_provision'      => false,
    'default_role'        => 'readonly',
    'disable_local_login' => false,
],
```

### Settings

| Key | Required | Description |
|-----|----------|-------------|
| `enabled` | yes | Set to `true` to activate OIDC |
| `display_name` | no | Button label on the login page (default: `SSO`) |
| `client_id` | yes | OAuth 2.0 client ID from your IdP |
| `client_secret` | yes | OAuth 2.0 client secret from your IdP |
| `discovery_url` | yes | IdP base URL — `/.well-known/openid-configuration` is appended automatically, or supply the full path |
| `redirect_uri` | yes | Must match exactly what is registered with the IdP |
| `scopes` | no | Space-separated scopes (default: `openid email profile`) |
| `auto_provision` | no | Create a local user on first OIDC login if none exists (default: `false`) |
| `default_role` | no | Role assigned to auto-provisioned users: `admin` or `readonly` (default: `readonly`) |
| `disable_local_login` | no | Hide the password form when OIDC is enabled (default: `false`). See [Disabling local login](#disabling-local-login) |

---

## IdP setup examples

### Google

1. Go to [Google Cloud Console](https://console.cloud.google.com/) → APIs & Services → Credentials
2. Create an **OAuth 2.0 Client ID** (type: Web application)
3. Add `https://ipam.example.com/oidc_callback.php` to **Authorized redirect URIs**
4. Copy the client ID and secret

```php
'discovery_url' => 'https://accounts.google.com',
```

### Microsoft Entra ID (Azure AD)

1. Azure Portal → App registrations → New registration
2. Set redirect URI to `https://ipam.example.com/oidc_callback.php` (platform: Web)
3. Under **Certificates & secrets**, create a new client secret
4. Note your **tenant ID**

```php
'discovery_url' => 'https://login.microsoftonline.com/{tenant-id}/v2.0',
```

### Okta

1. Okta Admin Console → Applications → Create App Integration (OIDC, Web Application)
2. Add `https://ipam.example.com/oidc_callback.php` to **Sign-in redirect URIs**
3. Copy the client ID and secret

```php
'discovery_url' => 'https://your-org.okta.com/oauth2/default',
```

### Keycloak

1. Keycloak Admin → Realm → Clients → Create
2. Set **Valid Redirect URIs** to `https://ipam.example.com/oidc_callback.php`
3. Enable **Client authentication** (confidential client)

```php
'discovery_url' => 'https://keycloak.example.com/realms/your-realm',
```

### Authentik

1. Authentik Admin → Applications → Providers → Create OAuth2/OpenID Connect Provider
2. Set redirect URI to `https://ipam.example.com/oidc_callback.php`

```php
'discovery_url' => 'https://authentik.example.com/application/o/your-app',
```

---

## User provisioning and linking

### Manual linking (recommended for existing installs)

By default, `auto_provision` is `false`. An admin must create or link accounts before any user can sign in via SSO.

**Option 1 — Link via the admin UI:**

1. Create or locate the local user account in **Admin → Users**
2. In the user's Actions panel, paste the IdP `sub` claim value into the **Link SSO** field and submit
3. The account is now linked; the user can sign in via SSO on their next visit

**Option 2 — Temporary auto_provision:**

Enable `auto_provision` briefly, have the user sign in once via OIDC, then disable it again. The `sub` claim is stored and subsequent logins work without provisioning.

### Auto-provisioning

With `auto_provision = true`:

1. On the first OIDC login, the `sub` claim is looked up in `users.oidc_sub` — no match found
2. An existing unlinked account is sought: first by matching `preferred_username`, then by matching `email` (against both the `username` and `email` columns)
3. If a match is found, the account is automatically linked to the `sub` claim and name/email are populated from the ID token if they were blank
4. If no match is found, a new account is created:
   - Username derived from `preferred_username` claim → `email` local-part → `sub` (fallback)
   - Name and email populated from the `name` and `email` claims
   - Unusable random password (account cannot be used for local auth)
   - Role set to `default_role` (default: `readonly`)

> Auto-provisioned accounts cannot log in with local credentials (the password is a random bcrypt hash). If OIDC becomes unavailable, an admin can set a proper password via **Admin → Users → Reset PW**.

### Name and email sync

On every OIDC login, if a user's **Name** or **Email** fields are blank, they are silently populated from the `name` and `email` claims in the ID token. Fields that have already been set are not overwritten — you can always edit them manually in **Admin → Users**.

### Unlinking an account

Admins can remove the OIDC link from any user in **Admin → Users** by clicking **Unlink SSO**. The local account remains active; the user can log in with their local password if one is set.

---

## Disabling local login

Set `'disable_local_login' => true` in the `oidc` config block to hide the username/password form on the login page. Users will only see the SSO button.

```php
'oidc' => [
    'enabled'             => true,
    // ... other settings ...
    'disable_local_login' => true,
],
```

**Emergency access:** Even with `disable_local_login` enabled, local login is always accessible at `login.php?local=1`. Keep at least one active local admin account as a break-glass fallback in case your IdP becomes unavailable.

---

## Troubleshooting

All OIDC errors are written to PHP's error log. The login page shows only a generic "SSO authentication failed" message to avoid leaking configuration details.

| Symptom | Likely cause |
|---------|-------------|
| "Could not reach the identity provider" | `discovery_url` is wrong or unreachable; check `allow_url_fopen` |
| "SSO authentication failed" after redirect | Check the PHP error log for the specific reason |
| "state mismatch" in error log | Session lost between `oidc_login.php` and `oidc_callback.php` — check session cookie settings |
| "No matching RSA JWK" | The IdP uses a key `kid` not in its published JWKS, or JWKS cache is stale |
| "No local user found for sub=..." | `auto_provision` is false and no account has been linked to this `sub` |
| ID token expired | Large clock skew between your server and the IdP — sync server time via NTP |
| Redirect URI mismatch error from IdP | `redirect_uri` in `config.php` must exactly match the value registered with the IdP |
