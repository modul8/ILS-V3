# ILS V3 Asset App

ILS V3 is a PHP + MariaDB web app for searching and managing field assets.

Asset types supported:

- Drains
- Culverts
- Bridges

## Features

- Separate pages per asset type
- Search by asset ID (for example `478`)
- Auto-open existing asset card when found
- Pre-filled create card when asset does not exist
- Work order and purchase order fields
- GPS coordinates + clickable Google Maps link
- Phone/browser GPS capture
- Notes history (with user attribution + timestamp)
- Contact records per asset
- Photo uploads per asset
- Secure login with role-based access (`admin`, `user`)

## Role Permissions

`admin`:

- Full read/write for asset details
- Edit WO/PO/GPS/contacts
- Create assets
- Add notes
- Upload photos
- Manage users (`admin_users.php`)

`user`:

- Read asset details
- Create new assets
- Add notes
- Upload photos
- Cannot edit WO/PO/GPS/contacts on existing assets

## Project Layout

- `web/index.php` - home/dashboard
- `web/login.php` - login page
- `web/setup_admin.php` - first admin bootstrap
- `web/admin_users.php` - user management (admin only)
- `web/drains.php` - drains page
- `web/culverts.php` - culverts page
- `web/bridges.php` - bridges page
- `web/api/index.php` - backend API
- `web/schema.sql` - DB schema and migration alters
- `web/config.sample.php` - configuration template

## Setup

1. Copy `web/config.sample.php` to `web/config.php`.
2. Edit DB credentials in `web/config.php`.
3. Run `web/schema.sql` against your MariaDB/MySQL database.
4. Deploy the contents of `web/` to your web root on TrueNAS.
5. Ensure `web/uploads/` is writable by the web server user.
6. Open `/setup_admin.php` once and create the first admin account.
7. Sign in at `/login.php`.

### Optional: Use Deploy Script

From repo root:

```bash
chmod +x deploy.sh
./deploy.sh
```

With DB migration enabled:

```bash
DB_MIGRATE=1 \
DB_HOST=127.0.0.1 \
DB_PORT=3306 \
DB_NAME=ils_v3 \
DB_USER=ils_v3_user \
DB_PASS='your_password' \
./deploy.sh
```

## Existing DB Migration Note

If this is an existing deployment and you already have tables, ensure these are applied:

```sql
ALTER TABLE asset_contacts MODIFY name VARCHAR(120) NULL;
ALTER TABLE asset_contacts MODIFY phone VARCHAR(60) NULL;
```

These make contact fields fully optional.

## Main URLs

- `/login.php`
- `/index.php`
- `/drains.php`
- `/culverts.php`
- `/bridges.php`
- `/admin_users.php` (admin only)

## Security Notes

- Authentication is session-based.
- Passwords are stored with PHP `password_hash`.
- API endpoints require a valid logged-in session.
- Keep `web/config.php` out of source control.

## Requirements

See [REQUIREMENTS.md](REQUIREMENTS.md).
