# R21 订单中心导出岗位版本误判修复固定版本

- 日期：2026-10-19
- 基线 HEAD：`5290bca75061c0c074ba78f504e4c5848f11b9dc`
- 状态：开发与自测完成，待产品经理确认验收
- 目标实例：本地开发环境；瑞昊仅执行只读诊断，未部署、未改库

## 问题与根因

瑞昊普通门店员工创建订单中心导出任务时，事务内权限快照把岗位 ID 规范为整数数组；异步 worker 从 `SystemStoreStaff` 模型读取时得到字符串数组。两者业务含义相同，但参与权限版本哈希的类型不同，导致任务在创建后一至三秒内被误判为“账号或当前岗位已变化”。

瑞昊只读证据：任务 `uqe_41c81a290a4a99cf747f506ce383fa67` 冻结权限版本为 `roles:b30e3aca742cfee00becff06e1100954`；员工 522 的岗位 `775,776` 以字符串数组重建时得到 `roles:2d78cab47a7f1313846a52f879c31728`，规范为整数数组后恢复为任务冻结版本。账号、员工、门店和岗位均有效。

## 本轮修改

### 后端源码

- `app/services/cashier/v3/CashierV3PermissionSnapshotServices.php`
  - 提供统一的岗位 ID 正整数规范化方法，任务创建继续使用同一口径。
- `app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryWorkerContextResolver.php`
  - worker 重建普通门店员工权限前复用统一规范，保留真实撤权和岗位变化拦截。

### 自动化测试

- `tests/cashier-v3/php/order-center-export-role-id-normalization-contract.php`
  - 覆盖数据库逗号字符串、JSON 字符串、模型字符串数组、整数数组和无效岗位 ID。
  - 校验任务创建和 worker 均调用同一规范化方法。

### 前端、迁移、生成物

- 前端源码：无修改。
- 数据库迁移：无。
- 构建生成物：无。

## 数据架构自检

- 权威数据源：当前有效门店任职、员工档案、岗位规则和员工数据范围。
- 事实粒度与统计口径：不改订单事实、导出查询、字段、合计、筛选或权限范围。
- 时点与历史：仅统一同一当前权限的类型表达，不改历史任务和历史业务数据。
- 权限：真实停用、撤权、换岗、换店和权限版本变化仍会停止旧任务。
- 事务、幂等、并发、冲销：任务创建事务、幂等键、worker 租约和业务冲销均未改变。
- 性能：仅对通常为个位数的岗位 ID 做线性规范化，无新增查询。
- 对账：瑞昊失败任务的冻结版本与规范化后的当前权限版本一致。

## 自测结果

- PHP 语法检查：通过。
- `ORDER_CENTER_EXPORT_ROLE_ID_NORMALIZATION=PASS`。
- `git diff --check`：通过。
- 本地 `mohe-cashier-app` 已重启，`http://127.0.0.1:18092/` 返回 HTTP 200。

## 文件 SHA-256

- `CashierV3PermissionSnapshotServices.php`：`62238d772358e68ab5f25ba6994f0290a1638f92abb6042c1f55910862e3f131`
- `CashierV3OrderCenterUnifiedQueryWorkerContextResolver.php`：`dd3663a69a0e46c387f751c15e463cd3462585282ed833a7488a9af3e9663025`
- `order-center-export-role-id-normalization-contract.php`：`3bd9fc177348d5fa5deb94790c3de2c217173b8a20c69a29bdcd55ee9a1fe155`
