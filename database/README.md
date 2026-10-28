# 演示数据库

`online_marketplace.sql` 是从 MariaDB 10.11 导出的完整演示库：

- 111 件商品（12 件校园种子 + 99 件旧库迁移）
- 3 个演示账号（admin / alice / bob）
- 3 条商品留言、1 条主页留言
- 0 笔订单、0 条评价

## 恢复

```bash
mariadb -uroot -p123456 -e "
  DROP DATABASE IF EXISTS online_marketplace;
  CREATE DATABASE online_marketplace CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mariadb -uroot -p123456 online_marketplace < online_marketplace.sql
```

`setup.sh` 全新安装时会自动导入这个文件。

> 演示账号密码和数据库密码都写在根目录 README 里，仅用于本地演示。
