# R29 院装领用多明细修复：本地自测与待验收版本

- 基线：本地 `release/store-cashier-c14-main` HEAD `2c76fa42df1a685e96041ad9876b710dec060ea2`。本闭环六个业务／迁移／测试文件的有序 SHA-256 清单指纹为 `887c5750b2c66f1a5cd784225475a7a24a8a4f527834cc6dfef5c616876a7f06`；其他脏工作区改动不属于本轮。
- 前端源码：无修改。后端源码：`后端代码/app/services/product/inventory/InventorySalonUsageServices.php`（`fe11c386fe06833c6ce1a6af16dfb21262d22be810f7f340f46cb2ae42852ca0`）。新增注释明确领用无来源行、退回保留来源唯一性。
- 数据库迁移：`后端代码/database/upgrades/2026-10-08-院装领用多明细修复/00-升级清单.md`（`6a696dcb63650375e12cfcb13844bfb606472decf67dd5989b035c573bc5f4bd`）、`01-正式升级.sql`（`adcb4f946982c1e617c6947a044eebd3564a24b84c4800611254f10223593061`）、`02-升级后验证.sql`（`bade4a14b6e4b4f6597ebf6af34faeafecf40e372918cb90e5512f2034de2b85`）。旧建表升级文件保持不变。
- 自动化测试：`tests/inventory/php/salon-usage-integration.php`（`ac91c563f8439edce2d18f3f2eea2129e371453560eaa3ad92446f2394f2fd9a`）、`tests/inventory/php/read-model-projection-contract.php`（`db3cb2f85c4723d6f47c2d992fa2d98800109f3646dce3d85bbf672d9b52db39`）。后者只把已经停用的院装退回命令断言改为现行作废接口断言。
- 工程脚本、生成物：无修改、无前端构建产物。验收证据：本文件和下列自测结果。

## 数据架构自检

- 权威源仍是院装领用单、明细、库存批次和批次移动事实；本次不新增事实类型或报表口径。每条 FEFO 批次分配各形成一条明细和一条移动事实；`source_usage_line_id=NULL` 只表示正向领用没有上游领用行，退回行继续引用原行。
- 业务日期及真实发生、成功和记录时间均由现有命令与移动事实承载，本次不改统计时点。门店、仓库、组织和操作人权限检查仍在原入口。历史项目与批次快照不重算。
- `Db::transaction` 包含单据、库存版本扣减、批次扣减和移动事实；请求幂等键与原有事实键不变。作废仍逐原批次反向恢复，已退回的旧领用单不能整单作废。多行写入失败必须整笔回滚。
- 原唯一键 `uk_document_source_line(document_id,source_usage_line_id)` 保留；MySQL 允许一单多个 NULL，同时阻止退回行重复引用同一来源。历史 ISSUE 行的 0 只转换为 NULL；原 RETURN 行不修改。原索引仍用于来源反查，无新增大范围查询。
- 对账方式：比对迁移前后原单和移动事实、NULL/0 数量、索引；多商品和跨批次领用比对三条明细、唯一移动事实、库存净额、幂等重放与作废冲销。生产迁移须先备份、先升级表再部署新 PHP，不可在产生多行 NULL 后直接回退为 NOT NULL。

## 实测

- 隔离库 `inventory_manual_inbound_test_20260730`（本机 MySQL 5.7、PHP 7.4）：`salon-usage-integration.php` 13 项通过、0 失败；迁移重复执行、历史 0 回填、三行领用、重放、整单作废和后续行失败回滚均通过。
- `read-model-projection-contract.php` 10 项通过；`salon-optional-project-contract.mjs` 通过；升级后验证 SQL 在隔离库执行通过。PHP 7.4 业务类语法与 `git diff --check` 通过。
- `inventory-v3-c3-contract.mjs` 在既有平台调拨默认总部仓断言处失败；该脚本不涉及本次院装数据写入或页面改动，未扩大范围修改。宿主机 PHP 8.5 缺少 Swoole，集成测试改用项目 PHP 7.4 容器完成。
- 本机 `18091` 所连的 Docker `ruihao` 库：迁移前 37 条 ISSUE 行为 0，1 条 RETURN 行；独立备份 `/tmp/r29-salon-local-20261008/authority-before.sql`，SHA-256 `95d5842f619c46a6622c8598f843744cc3067fef85fdfef28a78cd0f40d34c70`。本机迁移后字段可空、唯一键两列均保留，ISSUE=0 为 0，RETURN 无来源为 0。未修改瑞昊生产库。

状态：产品经理已确认本固定版本测试无问题，并授权本轮 commit、部署瑞昊和 push。生产数据库迁移仍按单独授权、备份和回滚预检执行；提交、部署与推送结果另行记录。生产环境在完成迁移及部署前仍会受旧唯一键问题影响。
