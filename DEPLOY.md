# Deployment checklist

## Required environment

- PHP 8.1+ with `pdo_mysql`, `curl`, `fileinfo`, `openssl` extensions enabled.
- MySQL 8 (or MariaDB 10.6+).
- Apache with `mod_rewrite`/`.htaccess` support (`AllowOverride All` on the
  `cashflow/` directory), or an equivalent Nginx `location` block that
  denies `config.php`, `db.php`, `*.sql`, `seed.php`, and the `lib/`,
  `modules/`, `admin/` subdirectories from direct requests — see
  `.htaccess`, `lib/.htaccess`, `modules/.htaccess`, `admin/.htaccess` for
  exactly what to translate.
- HTTPS terminated in front of the app (session cookies get the `Secure`
  flag automatically once `$_SERVER['HTTPS']` is set; `Strict-Transport-
  Security` is sent automatically over HTTPS).

## Environment variables

| Variable | Required | Purpose |
|---|---|---|
| `CASHFLOW_DB_HOST`, `CASHFLOW_DB_PORT`, `CASHFLOW_DB_NAME`, `CASHFLOW_DB_USER`, `CASHFLOW_DB_PASS` | yes | Database connection |
| `CASHFLOW_ENCRYPTION_KEY` | **yes in production** | Encrypts integration credentials (ANAF/SmartBill/Google OAuth secrets & tokens) at rest. Without it, a fixed fallback key is used and a warning is logged on every request — set a long random value and keep it out of version control. |
| `CASHFLOW_DEBUG` | no | Set to `1` only in local dev to see stack traces instead of the generic error page. |

## First deploy

1. Point the vhost at the `cashflow/` directory (or a directory containing
   it — the app is self-contained and doesn't need to be the vhost root).
2. Set the environment variables above.
3. Visit `index.php?p=login` once — the schema self-provisions on first
   request (`cashflow_migrate()`), same as `hr/modules/corectii.php`
   elsewhere in this repo.
4. Create the first platform admin by visiting `setup.php` once — it lets
   you create the very first admin account from the browser, then
   permanently disables itself the moment any platform admin exists
   (checked against the database on every request, not a one-time flag).
   Do this immediately after deploying, before anyone else can reach the
   URL. There is no other public signup flow.

   If you'd rather not expose that page at all (e.g. you already have
   database access), skip it and run this instead:
   ```sql
   -- after registering at least one user through the app, or inserting one:
   UPDATE cf_users SET is_platform_admin = 1 WHERE email = 'you@yourcompany.ro';
   ```
   From there, use `admin.php` → Firme/Utilizatori to create every other
   company and account.
5. Storage: `storage/uploads/` holds uploaded documents and is denied by
   `storage/.htaccess`. For an extra layer, point `CASHFLOW_ROOT`-relative
   storage at a path outside the web root entirely if your hosting setup
   allows it (the app only assumes the directory is writable).

## Third-party integrations — what "connected" actually requires

None of these can be made to work from application code alone; each
company must complete a real registration with the provider:

- **ANAF e-Factura**: register an OAuth application at
  `logincert.anaf.ro`, and have someone with a qualified digital
  certificate (USB token) complete the consent step once per company.
  Register the redirect URI shown on the Integrări page.
- **SmartBill**: generate an API token from the SmartBill account
  (My account → Integrations → API) and enter the account's username +
  that token.
- **Google Drive**: create a Google Cloud project, enable the Drive API,
  create an OAuth 2.0 Client ID (Web application), and register the
  redirect URI shown on the Integrări page.
- **ANAF CUI lookup** is the exception — it's a free public endpoint and
  works immediately once a plan has the `anaf_lookup` feature enabled.

## Not included (out of scope for application code)

- A payment gateway for subscription billing — plan assignment today is a
  platform-admin action (`admin.php` → Abonamente), not self-service
  checkout. Wiring a processor (Stripe, Netopia, etc.) would plug into
  `lib/subscriptions.php`'s `cashflow_assign_subscription()`.
- Backups, monitoring, log rotation, horizontal scaling, load balancing —
  standard hosting/ops concerns independent of this codebase.
- A true binary `.xlsx` writer — `modules/export.php` produces
  Excel-compatible CSV (UTF-8 BOM, `;` delimiter) rather than a generated
  `.xlsx` file, to avoid shipping an untested from-scratch OOXML writer.
  If real `.xlsx` output is needed later, add PhpSpreadsheet via Composer.
