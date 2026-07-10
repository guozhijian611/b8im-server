# b8im-server

b8im 后端控制面工程，基于 Saimulti 6.x + webman / Workerman。

本仓库承载：

- Saimulti 后端插件代码
- 平台端、租户端、公共 API
- 多租户、权限、菜单、配置中心
- 低代码生成器
- 数据库初始化结构

## 来源

初始源码导入自：

```text
https://cnb.cool/saithink/tenant/saas6.x
```

对应源目录：

```text
server/
db/
```

## 数据库

数据库文件位于：

```text
db/saimulti.sql
db/area_code.sql.gz
```

`area_code.sql` 原始文件超过 GitHub 单文件 100MB 限制，因此以 gzip 压缩形式保存。

本机默认开发数据库：

```text
DB_NAME = nb8im
DB_USER = root
DB_PASSWORD = root
```

本机需要导入地区数据时先解压：

```bash
gzip -dk db/area_code.sql.gz
```

## 本机配置

复制示例配置：

```bash
cp .env.example .env
```

再按本机数据库、Redis 配置修改 `.env`。

## 数据库迁移

数据库结构变更使用 Phinx 管理。`phinx.php` 是配置文件，默认读取 `.env` 并连接
`DB_NAME` 指定的数据库；迁移和种子文件分别放在：

```text
database/migrations/
database/seeds/
```

通过 webman 命令执行 Phinx：

```bash
php webman phinx:test --environment=default
php webman phinx:status
php webman phinx:create AddExampleTable
php webman phinx:migrate
php webman phinx:rollback
php webman phinx:seed-create ExampleSeeder
php webman phinx:seed-run
```

`phinx.php` 不是可执行入口，不要使用 `php phinx.php`。
