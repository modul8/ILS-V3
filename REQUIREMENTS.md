# Requirements

## Runtime Requirements

- PHP `8.1+` (recommended `8.2+`)
- MariaDB `10.5+` or MySQL `8.0+`
- Web server: Nginx or Apache with PHP-FPM/mod_php
- Writable uploads directory: `web/uploads/`

## PHP Extensions

Required:

- `pdo`
- `pdo_mysql`
- `json`
- `session`
- `fileinfo`
- `mbstring`

Recommended:

- `openssl`
- `curl`

## Database Requirements

- UTF-8 capable DB (`utf8mb4`)
- Ability to create/alter tables and foreign keys
- User with privileges:
  - `SELECT`
  - `INSERT`
  - `UPDATE`
  - `DELETE`
  - `CREATE`
  - `ALTER`
  - `INDEX`

## File/Folder Requirements

- `web/config.php` must exist (copy from `web/config.sample.php`)
- `web/config.php` should be readable by PHP and not committed to Git
- `web/uploads/` must be writable by web server user (for photo uploads)

## Browser Requirements

- Modern mobile/desktop browser (Chrome, Edge, Safari, Firefox)
- Geolocation permission enabled for GPS capture
- Camera/file access permission for photo uploads

## Production Recommendations

- HTTPS enabled (TLS certificate)
- Regular database backups
- Restrict direct access to server admin interfaces
- Strong passwords for all users (especially admin accounts)
