# 收银 V3 永久回归测试

目录：`tests/cashier-v3/`（以下命令均从 Git 仓库根目录执行）

## 运行

```bash
# 永久套件（隔离：只读挂载后端、mktemp .env、tmpfs runtime）
bash tests/cashier-v3/run-all.sh
```

## 隔离规则

1. 后端 `后端代码/` **只读**挂载进测试容器；运行前须已通过 Composer 安装 `后端代码/vendor/`  
2. 测试 `.env` 由 `mktemp` 生成，**只读**挂到容器 `/var/www/html/.env`  
3. `runtime` / 临时 bundle 使用 tmpfs 或临时目录  
4. **禁止**复制真实 `.env` / `.env.docker` 到证据目录  
5. 运行前后记录真实 `.env` SHA-256，不一致则失败  
6. `trap` 清理容器、网络、临时配置  
7. **禁止**改写真实 `.env`
8. SQL 只执行 Git 内 `后端代码/database/upgrades/`；仓外交付目录仅为逐字节一致的可选镜像
9. 干净 Git 检出可不带仓外交付镜像；发布验收使用 `C1A_REQUIRE_DELIVERY_MIRROR=1` 强制镜像存在且一致
10. 默认按 `docker/php74-runtime.Dockerfile` 的 SHA 构建 PHP 7.4 测试镜像，并强制 `bcmath`、`zip`、`mbstring`、`pdo_mysql`

## 子套件

| 路径 | 说明 |
|------|------|
| `php/unit-contract.php` | 单元契约：权限、readiness、注册 freeze 等 |
| `php/integration-gateway.php` | MySQL 集成：幂等、versions、dispatcher |
| `php/member-prepare-schema.php` | C5 正式迁移前的最小旧会员表基线 |
| `php/member-integration.php` | C5 会员选择、DataScope、并发建档、编号、档案项与 Outbox 事务 |
| `php/container-resolve-check.php` | 容器真实解析 + Composition Root |
| `php/export-backend-manifest.php` | 导出后端 action manifest |
| `js/frontend-manifest-sync.mjs` | AST 扫描 + 前后端 manifest 对齐 |
| `js/bridge-contract.mjs` | esbuild 打包真实 `cashierV3Bridge.js` |
| `js/envelope-bridge-check.mjs` | 跨端信封样本核对 |
| `sql/run-sql-matrix.sh` | C1 SQL 正反例结构合同（MySQL 5.6.51） |
| `migration-mirror-contract.sh` | 仓内迁移 canonical、内部 SHA 与仓外交付镜像一致性 |
| `requirements-matrix.json` | 硬门禁 ID → 测试映射 |
| `../unified-query/php/contract.php` | AST、类型、空值、精度、权限先行、稳定分页、聚合性能与全口径一致性 |
| `../unified-query/php/metadata-integration.php` | 个人/共享权限、别名、不可变版本、引用失效与导出租约 |
| `../unified-query/php/gateway-integration.php` | 真实 Dispatcher、会员 provider、截止日、XLSX worker 与下载权限 |
| `../unified-query/php/member-provider-integration.php` | 真实 Gateway 下有效卡、剩余项目、待还欠款、到店去重及服务人员历史快照口径 |
| `../unified-query/js/gateway-envelope-contract.mjs` | PHP 固定信封经生产 bridge 的跨端六步回归 |
| `js/unified-query-frontend-contract.mjs` | 统一查询抽屉、字段改名、状态切换、导出和列表消费合同 |
| `../unified-query/sql-matrix.sh` | 统一查询 canonical 迁移 MySQL 5.6.51 正反例矩阵 |

## MySQL

默认镜像：`mysql:5.6.51`（`run-all.sh` 会记录 digest 与 `SELECT VERSION()` 输出）。

统一查询集成测试会在主隔离库执行 canonical 迁移，并在独立 MySQL 5.6.51
容器中重复执行迁移矩阵；不会读取或写入远程数据库。XLSX 只写入容器
`runtime` tmpfs，跨端 JSON 样本只写入本次证据目录。

## 通过条件

仅当 `requirements-matrix.json` 中全部 required 门禁均有 `GATE_PASS=<id>` 且各 runner 退出码为 0 时，输出：

```
==== ALL GATES PASSED ====
```
