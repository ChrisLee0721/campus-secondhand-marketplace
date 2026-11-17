# Demo database

`online_marketplace.sql` is a complete demo database exported from MariaDB 10.11:

- 111 listings (12 campus seeds plus 99 migrated from the legacy database)
- 3 demo accounts (admin / alice / bob)
- 3 listing comments, 1 profile comment
- 0 orders, 0 reviews

## Restore

```bash
mariadb -uroot -p123456 -e "
  DROP DATABASE IF EXISTS online_marketplace;
  CREATE DATABASE online_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -uroot -p123456 online_marketplace < online_marketplace.sql
```

`setup.sh` imports this file automatically on a fresh install.

> The demo and database passwords are documented in the top-level README and are for local demos only.
