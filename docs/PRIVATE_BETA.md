# NileBit POS for Retail private beta

## Render free public beta

This is a free, invite-only public beta limited to 30 days. The Render free
PostgreSQL database expires after 30 days and has no backups. Export the data
before the expiry deadline or upgrade the database before the beta ends.

For this free deployment, set `RUN_MIGRATIONS_ON_BOOT=true` in Render. The
container then runs `php artisan migrate --force --no-interaction` before the
application starts. This replaces Render's paid-only pre-deploy command.

For a paid production deployment, set `RUN_MIGRATIONS_ON_BOOT=false` and run
the migration as a Render pre-deploy command instead.

Public self-registration is disabled in production. Existing invited users can
continue to sign in and access only the shops assigned to them.

## Production environment

Set these values in the backend service environment. Do not commit real
credentials, database URLs, or application keys.

```dotenv
APP_URL=https://api.nilebitlabs.com
CORS_ALLOWED_ORIGINS=https://pos.nilebitlabs.com,https://nilebitlabs.com
PUBLIC_REGISTRATION=false
```

`CORS_ALLOWED_ORIGINS` is a comma-separated allow-list of exact browser
origins. Do not use `*` in production.

## Invite an owner

Run this from the backend release environment after migrations have completed:

```bash
php artisan beta:invite-owner \
  --name="Owner Name" \
  --email="owner@example.com" \
  --organization="Business Name" \
  --shop="Main Shop"
```

The command securely prompts for the temporary password; it cannot accept one
as a command-line argument, does not print it, and never issues an API token.
It creates the user, organisation, first shop, and owner membership in one
database transaction. Share the temporary password only through an approved
secure channel.
