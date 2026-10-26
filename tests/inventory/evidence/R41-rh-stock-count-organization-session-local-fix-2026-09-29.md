# R41 瑞昊多门店会话盘点失败：本地修复与待验收版本

- 状态：本地实现和专项自测完成，**待产品经理确认验收**；未 commit、未部署、未执行瑞昊数据库迁移、未 push。线上未保存的 805 行页面没有被操作。
- 现象与根因：瑞昊后端日志出现 `inventory_stock_count_query_scope_denied` / `inventory_stock_count_scope_denied`。代码与线上版本一致；组织选店 JWT 的 `id` 是 `cashier_v3_store_session.id`，而旧盘点草稿、确认和读取均强制按 `system_store_staff.id` 查任职，故多门店组织会话被拒绝。仅更换前端缓存不能解决。
- 修复：由已认证会话解析有类型的创建人。普通门店任职为 `STORE_STAFF + staff.id`；可操作组织会话为 `EMPLOYEE + employee_id`。草稿的创建、读取、列表、版本删除、确认和正式单审计均使用该身份；只读 delegated 会话不可写且不显示草稿。租户、门店、默认仓及组织路径继续由服务端限定。通用查询的设置/回执仍使用原登录会话 ID，避免扩散影响。
- 数据库：新增两个 `operator_type` 字段及草稿定型索引；既有门店员工记录默认 `STORE_STAFF`，历史平台仓正式单置为 `PLATFORM_ADMIN`。迁移可重复执行，应用发布前必须逐实例先升级。已在临时本地 MySQL 测试容器及本地 `ruihao` 调试库执行并验证；未连接或修改瑞昊线上库。测试容器已移除。

## 固定版本

基线 HEAD：`05e76b4de6cca5de9e79f20e046c4ae90ed8fb2d`（共享工作区在本次操作期间有其他任务提交；以下文件指纹界定本闭环）。按下列文件名与 SHA-256 顺序组合的清单指纹：`f67bb35461a656a54dd7342cdb9b393a9edfd612fb680f3154accc8050aebf56`。

```text
aa3ec01ce77cff8018de4a559a820f35fb2c8e706e11cc09d946769fc30cb006  后端代码/app/services/product/inventory/InventoryStockCountActorServices.php
b020bbe3377b3f944352b80df985e777b5fce073be7c45bfa8d3a2d74f1a0330  后端代码/app/services/product/inventory/InventoryStockCountDraftServices.php
148a0f45685cc7c55e6962e27f32134eee70fe6af6767faea2227c98240a0a4d  后端代码/app/services/product/inventory/InventoryStockCountServices.php
bb49fd2a08813d8d15b27e64fddb28b793213ded15d0763bfe28c01e8f890bc0  后端代码/app/services/product/inventory/InventoryStockCountQueryServices.php
3d839afdd011c0d34194a146b6762fac6b239237c397b52489d9f9be3af6daf9  后端代码/app/services/product/inventory/query/InventoryStoreUnifiedQueryContextFactory.php
d127ffad14b19f8e665d42882c35784af07fd8a6cf606112778071e2a0064b33  后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php
dd0643b9c6f694bea38b57060f44ddee18e3e1d721d9cb0ea205d2279a23546b  后端代码/app/controller/store/product/inventory/InventoryStockCount.php
161065a1a5c9ba5890a004c747a70c7a6c664b64aa213fce1f58eadd7a000a88  后端代码/app/controller/store/product/inventory/InventoryStockCountQuery.php
b1e27497a3c7b6fa0fcd2d6ba685f43c7b3efa3824ebf156bf19cc0e90932fdb  后端代码/database/upgrades/2026-09-29-盘点操作人身份类型/00-升级清单.md
e7f53174f73737f91bb72717e41ac29774f3837876bbbb8878e290d7d2682617  后端代码/database/upgrades/2026-09-29-盘点操作人身份类型/01-升级前检查.sql
293f0da2c444f829c459cb2a888e541e1184ce271659f6858b26eb0877c19031  后端代码/database/upgrades/2026-09-29-盘点操作人身份类型/02-正式升级.sql
8094138ec8c911b5e35e7820e720073031e306011da5464e8b21a4c0abea8918  后端代码/database/upgrades/2026-09-29-盘点操作人身份类型/03-升级后验证.sql
fcf0fc82adfe19f01da6da02efb021939c9aef1379aae93ddef8b045fbf1b069  tests/inventory/php/manual-test-bootstrap.php
d0e4f38accd9dfc93e0e33bbd88e02b967576d880cc923acc0c4896065b99e34  tests/inventory/php/stock-count-draft-integration.php
be488fd6b988dbefb1c8660f1705f7eeae6fefc2fb6bfd081417faee62df5803  tests/inventory/php/stock-count-integration.php
95474fda9da9e724ce2d6eba1a49e4ef2bcd8b1faafa59bff06043da22cea48c  tests/inventory/php/stock-count-organization-actor-integration.php
```

文件分组：前端源码无；后端源码 8 文件；数据库迁移 4 文件；自动化测试 4 文件；工程脚本无；生成物无；本文件为验收证据。新增/更新的业务注释位于创建人解析、草稿归属、确认范围、统一查询和迁移 SQL；未改动其他脏工作区内容。

## 自测

- 8 个变动的 PHP 业务文件 `php -l` 全通过，`git diff --check` 通过。
- 独立临时 MySQL 5.7 测试库：`STOCK_COUNT_ORGANIZATION_ACTOR_INTEGRATION_OK`。覆盖保存、换会话读取、列表归属、同号 staff 不可见、delegated 不可写、版本删除、确认前组织路径及创建人解析。
- 既有独立库存夹具库：`INVENTORY_STOCK_COUNT_DRAFT_RESULT failed=0`。14 项业务断言包括组织会话跨登录正式确认、流水只记一次、正式单的 `EMPLOYEE + employee_id` 审计身份；未使用瑞昊线上数据。
- 迁移在新库首次执行和再次执行均通过；字段与索引验证通过。
- 本地 `ruihao` 调试库执行前，对两张盘点表做定向备份：`/tmp/r41-local-count-uvvH8z/count-before.sql`，SHA-256 `cd41e3c7ae05618d27c75737350b3b7b551e3e27a7f8df1bac52c134d35eb1b4`。升级后两表字段及索引验证通过，既有草稿 2 行、正式单 2 行均保留。该备份只用于本地回滚，不属于源码或瑞昊远程备份。
- `inventory-api-contract.mjs` 57/57，盘点草稿列表、提交、盘点日期 JS 合同均通过；库存统一查询注册合同和草稿周期 PHP 合同通过。
- 边界：没有在瑞昊真实 805 行盘点页面执行保存/确认；没有对瑞昊远程库或服务做任何写入。真实盘点完成还须在迁移、部署及上线核验获授权后进行。
