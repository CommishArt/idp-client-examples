# Commish SSO — Client Example Apps

Two example Symfony 8 applications demonstrating OAuth2/OIDC + PKCE integration
with the [Commish IDP](https://github.com/CommishArt/idp).

```
app1/  → Admin Panel       (port 8011)
app2/  → Customer Portal   (port 8012)
```

The identity provider itself lives in a separate repo:
**[CommishArt/idp](https://github.com/CommishArt/idp)** (port 8010)

---

## Prerequisites

- **PHP 8.5+** with extensions: `sodium`, `pdo_pgsql`, `openssl`, `mbstring`
- **Symfony CLI** (`symfony.exe` on Windows / `symfony` on Linux/Mac)
- **Docker Desktop** (for PostgreSQL and Redis only)
- **Composer** 2.x
- The **IDP** running — clone and set up [CommishArt/idp](https://github.com/CommishArt/idp) first

---

## Quick start

```bash
# Start PostgreSQL and Redis, install dependencies, run migrations:
bash setup.sh
```

Then start the two apps in separate terminals:

```bash
symfony.exe serve --dir=app1 --port=8011 --no-tls
symfony.exe serve --dir=app2 --port=8012 --no-tls
```

The IDP must already be running on port 8010. See [CommishArt/idp](https://github.com/CommishArt/idp) for its setup instructions.

---

## Test users

These are loaded by the IDP's fixtures:

| Email | Password | App access | Roles |
|---|---|---|---|
| `admin@example.com` | `password` | App1 + App2 | `ROLE_ADMIN` (App1), `ROLE_USER` (App2) |
| `editor@example.com` | `password` | App1 only | `ROLE_EDITOR` (App1) |
| `customer@example.com` | `password` | App2 only | `ROLE_USER` (App2) |

---

## OAuth2 / PKCE flow

```
Browser → App1/App2
            ↓  /login
              generates code_verifier, stores in session
              computes code_challenge = BASE64URL(SHA256(verifier))
            ↓  redirects to IdP /oauth/authorize?code_challenge=...
          IdP authenticates user, stores code_challenge with auth code
            ↓  redirects back with ?code=...&state=...
          App1/App2 /oauth/callback
            ↓  retrieves code_verifier from session
            ↓  POST /oauth/token  { code, code_verifier, client_id, client_secret }
          IdP verifies BASE64URL(SHA256(code_verifier)) == stored code_challenge
            ↓  issues JWT access token + refresh token
          App calls GET /oauth/userinfo with Bearer token
            ↓  receives: sub, email, allowed_apps, roles
          App provisions/updates local User, starts session
```

**PKCE is required** — the IDP rejects any authorization request without a
`code_challenge`. The PKCE flow is implemented directly in `MainController` and
`OidcAuthenticator`; it does not rely on KnpU's `OAuth2PKCEClient`.

Access tokens live for **15 minutes**. Refresh tokens live for **30 days** and are
rotated on each use.

---

## Registering a new user and controlling app access

1. Visit `http://localhost:8010/register` and create an account.
2. The new user has no `allowed_apps` by default — they can log in to the IdP but
   will be denied by any client app.
3. To grant access, update the user in the IdP database:

```sql
UPDATE "user"
SET allowed_apps = '["app1", "app2"]',
    app_roles    = '{"app1": ["ROLE_USER"], "app2": ["ROLE_USER"]}'
WHERE email = 'newuser@example.com';
```

---

## Adding a new client application

1. **Register the client in the IdP database:**
   ```sql
   INSERT INTO oauth_client (name, client_id, client_secret, redirect_uris, allowed_scopes, allowed_apps)
   VALUES ('My App', 'myapp-client', '<bcrypt hash of secret>',
           '["http://localhost:8013/oauth/callback"]',
           '["openid","email","profile","roles"]',
           '["myapp"]');
   ```

2. **Create a new Symfony app:** `symfony new myapp --webapp --no-git`

3. **Set environment variables** in `myapp/.env`:
   ```
   IDP_BASE_URL=http://localhost:8010
   IDP_CLIENT_ID=myapp-client
   IDP_CLIENT_SECRET=<plain secret>
   APP_URL=http://localhost:8013
   ```

4. **Install dependencies:**
   ```bash
   composer require knpuniversity/oauth2-client-bundle league/oauth2-client
   ```

5. **Copy the OIDC stack** from `app1/src/`:
   - `OAuth/IdpProvider.php` — unchanged
   - `Security/OidcAuthenticator.php` — change `APP_KEY = 'myapp'` and the access-denied message
   - `Entity/User.php`, `Repository/UserRepository.php` — unchanged
   - `Controller/MainController.php` — change `APP_KEY` equivalent and dashboard template; the PKCE verifier generation in `login()` is unchanged
   - `config/packages/knpu_oauth2_client.yaml` — unchanged
   - `config/packages/security.yaml` — unchanged
   - `config/services.yaml` — unchanged

6. **Grant users access** — add `"myapp"` to their `allowed_apps` array in the IdP.

---

## Architecture notes

- **No passwords in client apps** — app1 and app2 have no `password` column.
  Authentication is entirely delegated to the IdP.
- **Roles are synced on every login** — the client app's local `User.roles` is
  overwritten from the `roles` JWT claim on each successful callback, so the IdP
  remains the single source of truth.
- **PKCE is required** — the IDP enforces `code_challenge` on every authorization
  request. The verifier is generated in `MainController::login()`, stored in the
  PHP session, and retrieved in `OidcAuthenticator::authenticate()`.
- **Passkeys bypass TOTP** — a passkey is already a phishing-resistant second
  factor; requiring TOTP on top would be redundant.
