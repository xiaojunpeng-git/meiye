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

## MySQL

默认镜像：`mysql:5.6.51`（`run-all.sh` 会记录 digest 与 `SELECT VERSION()` 输出）。

## 通过条件

仅当 `requirements-matrix.json` 中全部 required 门禁均有 `GATE_PASS=<id>` 且各 runner 退出码为 0 时，输出：

```
==== ALL GATES PASSED ====
```
