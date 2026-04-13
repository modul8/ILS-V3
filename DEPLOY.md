# Deploy Guide (TrueNAS)

This is a practical deployment checklist for ILS V3 on your TrueNAS-hosted web stack.

Quick option: run [`deploy.sh`](./deploy.sh) from repo root and override env vars as needed.

## 1) Preflight

- Confirm PHP version is `8.1+`.
- Confirm MariaDB/MySQL is reachable from the app host.
- Confirm web root path (example): `/mnt/evo-pool/apps/ils_v3/`
- Confirm DB backup strategy is available.

## 2) Backup (Before Deploy)

Database backup (example):

```bash
mysqldump -u <db_user> -p <db_name> > ils_v3_backup_$(date +%F_%H%M).sql
```

App folder backup (example):

```bash
cp -a /mnt/evo-pool/apps/ils_v3 /mnt/evo-pool/apps/ils_v3_backup_$(date +%F_%H%M)
```

## 3) Upload App Files

From your local repo, sync `web/` contents to server app directory.

Example `rsync` (adapt paths):

```bash
rsync -av --delete \
  --exclude 'config.php' \
  --exclude 'uploads/' \
  ./web/ \
  /mnt/evo-pool/apps/ils_v3/
```

Then sync static assets + code (keeping persistent uploads/config):

- `config.php` remains server-local
- `uploads/` remains server-local

Script equivalent:

```bash
chmod +x deploy.sh
DEST_DIR=/mnt/evo-pool/apps/ils_v3/ ./deploy.sh
```

## 4) Ensure Required Files

- `config.php` exists on server (copied from `config.sample.php` and edited)
- `uploads/` exists and is writable

Example:

```bash
mkdir -p /mnt/evo-pool/apps/ils_v3/uploads
chmod -R u+rwX,go+rX /mnt/evo-pool/apps/ils_v3
chmod -R u+rwX,go+rwx /mnt/evo-pool/apps/ils_v3/uploads
```

If you use ACLs on TrueNAS, set ACLs for your web service user instead of broad chmod.

## 5) Database Migration

Run:

```sql
SOURCE /path/to/schema.sql;
```

If this is an existing DB and you only need contact optionality:

```sql
ALTER TABLE asset_contacts MODIFY name VARCHAR(120) NULL;
ALTER TABLE asset_contacts MODIFY phone VARCHAR(60) NULL;
```

## 6) First-Time Setup

If no users exist:

- Open `/setup_admin.php`
- Create first admin account
- Sign in at `/login.php`
- Create user accounts in `/admin_users.php`

## 7) Smoke Test Checklist

- `/login.php` loads and login works
- `drains/culverts/bridges` pages load
- Search existing asset by ID (e.g. `478`)
- Create new asset as `user`
- Add note and upload photo as `user`
- Confirm `user` cannot edit WO/PO/GPS/contacts on existing asset
- Confirm `admin` can edit WO/PO/GPS/contacts
- Click map link and verify pin opens in Google Maps

## 8) Rollback

If deployment fails:

1. Restore app folder backup.
2. Restore DB backup:

```bash
mysql -u <db_user> -p <db_name> < ils_v3_backup_<timestamp>.sql
```

3. Restart/reload web service if required.

## 9) Post-Deploy Notes

- Keep `config.php` out of Git.
- Keep HTTPS enabled.
- Rotate admin passwords periodically.
- Schedule DB backups.
