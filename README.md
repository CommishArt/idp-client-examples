# Commish SSO — Local Dev Environment

A working OAuth2/OIDC identity provider built with Symfony 8, plus two example client applications demonstrating centralised authentication.

```
/idp    → Identity Provider (port 8010)
/app1   → Admin Panel       (port 8011)
/app2   → Customer Portal   (port 8012)
```

---

## Prerequisites

- **PHP 8.5+** with extensions: `sodium`, `pdo_pgsql`, `openssl`, `mbstring`
- **Symfony CLI** (`symfony.exe` on Windows / `symfony` on Linux/Mac)
- **Docker Desktop** (for PostgreSQL and Redis only)
- **Composer** 2.x
- **OpenSSL** (bundled with Git for Windows at `C:\Program Files\Git\usr\bin\openssl.exe`)

---

## Quick start

```bash
# From the repo root:
bash setup.sh
```

This will:
1. Start PostgreSQL and Redis via Docker Compose
2. Run `composer install` for all three apps
3. Run database migrations for all three apps
4. Generate an RSA keypair for JWT signing (IdP only)
5. Load test fixtures into the IdP database

Then start the three servers in separate terminals:

```bash
symfony.exe serve --dir=idp  --port=8010 --no-tls
symfony.exe serve --dir=app1 --port=8011 --no-tls
symfony.exe serve --dir=app2 --port=8012 --no-tls
```

---

## Test users

| Email | Password | App access | Roles |
|---|---|---|---|
| `admin@example.com` | `password` | App1 + App2 | `ROLE_ADMIN` (App1), `ROLE_USER` (App2) |
| `editor@example.com` | `password` | App1 only | `ROLE_EDITOR` (App1) |
| `customer@example.com` | `password` | App2 only | `ROLE_USER` (App2) |

---

## OAuth2 flow

```
Browser → App1/App2
            ↓  /login  (redirects to IdP)
          IdP /oauth/authorize
            ↓  user logs in + consents
          IdP redirects back with ?code=...
            ↓
          App1/App2 /oauth/callback
            ↓  POST /oauth/token (exchanges code for tokens)
          IdP issues JWT access token + refresh token
            ↓
          App calls GET /oauth/userinfo with Bearer token
            ↓  receives: sub, email, allowed_apps, roles
          App provisions/updates local User, starts session
```

Access tokens live for **15 minutes**. Refresh tokens live for **30 days** and are rotated on each use. Each device login gets its own refresh token and session row, visible and revocable from the IdP session dashboard.

---

## Registering a new user and controlling app access

1. Visit `http://localhost:8010/register` and create an account.
2. The new user has no `allowed_apps` by default — they can log in to the IdP but will be denied by any client app.
3. To grant access, update the user in the database:

```sql
UPDATE "user"
SET allowed_apps = '["app1", "app2"]',
    app_roles    = '{"app1": ["ROLE_USER"], "app2": ["ROLE_USER"]}'
WHERE email = 'newuser@example.com';
```

Or add a Symfony command / admin UI that sets `User::setAllowedApps()` and `User::setAppRoles()`.

---

## Social login

Users can sign in with Google or GitHub. The flow is:

1. User clicks "Continue with Google/GitHub" on the IdP login page.
2. If a `SocialAccount` row exists for that provider ID → log in directly.
3. If no `SocialAccount` exists but the email matches an existing account → show a **conflict page**. The user must log in with their original method first, then link the social provider from Account Settings.
4. If no match at all → create a new `User` + `SocialAccount` (social signup).

### Linking additional providers

Once logged in, go to **Account → Linked Social Accounts** to link or unlink Google/GitHub. The app sets a `social_link_intent` session key before redirecting to the provider. On callback, if this key is present the flow is treated as a link operation, not a login.

Unlinking is blocked if it would leave the account with no remaining login method (no password, no other social account, no passkeys).

### Why we never auto-link by email

Auto-linking social accounts by email is a well-known account takeover vector: a malicious OAuth provider can claim any email address it wants. If we linked accounts automatically, an attacker could set up a fake OAuth app, claim `victim@example.com` as the email, and silently take over the victim's account.

Instead we only match on `providerUserId` — the immutable numeric ID assigned by Google or GitHub — which cannot be faked by a different provider.

---

## Adding a new client application

1. **Register the client in the IdP database** — either via fixture or directly:
   ```sql
   INSERT INTO oauth_client (name, client_id, client_secret, redirect_uris, allowed_scopes, allowed_apps)
   VALUES ('My App', 'myapp-client', '<bcrypt hash of secret>',
           '["http://localhost:8013/oauth/callback"]',
           '["openid","email","profile","roles"]',
           '["myapp"]');
   ```

2. **Create a new Symfony app** with `symfony new myapp --webapp --no-git`.

3. **Set environment variables** in `myapp/.env`:
   ```
   IDP_BASE_URL=http://localhost:8010
   IDP_CLIENT_ID=myapp-client
   IDP_CLIENT_SECRET=<plain secret>
   APP_URL=http://localhost:8013
   ```

4. **Install dependencies**: `composer require knpuniversity/oauth2-client-bundle league/oauth2-client`

5. **Copy the OIDC stack** from `app1/src/`:
   - `OAuth/IdpProvider.php` — unchanged
   - `Security/OidcAuthenticator.php` — change `APP_KEY = 'myapp'` and the access-denied message
   - `Entity/User.php`, `Repository/UserRepository.php` — unchanged
   - `Controller/MainController.php` — change the dashboard template
   - `config/packages/knpu_oauth2_client.yaml` — unchanged
   - `config/packages/security.yaml` — unchanged
   - `config/services.yaml` — unchanged

6. **Grant users access** — add `"myapp"` to their `allowed_apps` array in the IdP.

---

## Session storage

| Store | What |
|---|---|
| Redis (`idp_sess_*`) | IdP PHP session — login state, 2FA progress, OAuth flow params |
| DB `user_session` | One row per device per app — refresh token ID, IP, user agent. Shown in the session dashboard; each row can be independently revoked |
| DB `o_auth_refresh_token` | The actual refresh token record — revoked when the session row is revoked or on token rotation |

---

## Architecture notes

- **No passwords in client apps** — app1 and app2 have no `password` column. Authentication is entirely delegated to the IdP.
- **Roles are synced on every login** — the client app's local `User.roles` is overwritten from the `roles` JWT claim on each successful callback, so the IdP remains the single source of truth.
- **PKCE** is supported on the authorization endpoint — client apps should use it in production.
- **Passkeys bypass TOTP** — a passkey is already a phishing-resistant second factor; requiring TOTP on top would be redundant and annoying.
