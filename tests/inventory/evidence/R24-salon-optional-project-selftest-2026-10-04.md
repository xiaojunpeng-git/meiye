# R24 院装耗材领用：核销项目可留空（开发与自测）

- 基线 HEAD：`ba0392b846f018d3cc3a9e2ac3216d93bd9d17f0`。
- 范围：仅院装耗材领退单的项目关联变为可选；不实施本轮已取消的“按可作为院装耗材开关筛选商品”。
- 状态：本地开发、自测完成；待产品经理确认验收。未 commit、未部署、未 push，未连接瑞昊生产库。

## 数据底层与业务边界

- 权威单据为 `eb_inventory_salon_usage_document` 与明细；项目未关联时写入 `project_id = 0`、空的服务端项目名称快照。现有建表定义支持 `project_id` 默认 0，无需改表或迁移；历史已执行升级文件不回写。
- 领用和退回依旧以门店、仓库、耗材 SKU、原领用明细、库存批次流水为事实边界；无项目不是“自动归到某个项目”，也不产生核销事实。退回仍引用原领用明细并核对项目 ID 0，不能跨单退回。
- 日期、数量、批次成本与出入库金额口径不变；列表和详情仅把空项目展示为“未关联项目”，不改后端统计或数据权限。
- 原有事务、库存锁、数量上限与幂等指纹保留；`project_id = 0` 进入指纹，正项目 ID 仍须服务端验证当前门店可用项目及名称。客户端传来的项目名称不被信任。
- 旧的升级后验证 SQL 形成于项目必填时期；此闭环以新增契约和隔离库集成测试验证新口径，不修改已执行迁移。无需历史数据回填。

## 固定文件范围与 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/inventory-vue3/src/App.vue` | `d00f1d8823c020117f9fac71f13a225f4789a3849a39c36b87e2fff7cb45229e` |
| 前端源码 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `a1e2fbbc06e25405e1d8e62b15858e26ec031b105fd2778f899cd968533e181f` |
| 后端源码 | `后端代码/app/services/product/inventory/InventorySalonUsageServices.php` | `3de1c09bffd18f5c04ca316cbc121e7905aabde8c2b5630909632c035d0a0564` |
| 自动化测试 | `tests/inventory/php/salon-usage-integration.php` | `07574f29a42dc4e59c290601f837d12eb36716c43f1d7d6b90f471c774999d10` |
| 自动化测试 | `tests/inventory/js/salon-optional-project-contract.mjs` | `5fc55a11b1885afe50ffa051bd8ff1080e59d99a292d9403ac3ee16aefc9780e` |

- 上述受跟踪文件补丁 SHA-256：`c137cd015b12412d49d7138194ff506bc90d23f89b19449aaf30e043578716ff`。
- 数据库迁移、工程脚本：无。生成物：`inventory-vue3/dist/`，仅本机构建、未提交。验收证据：本文件。
- 注释同步：`InventoryBusinessModal.vue`、`App.vue`、`InventorySalonUsageServices.php`、`salon-usage-integration.php`、`salon-optional-project-contract.mjs` 的本轮逻辑均补充了边界说明。

## 自测结果

- PHP 语法检查：服务与集成测试脚本均通过。
- JS 契约：`SALON_OPTIONAL_PROJECT_CONTRACT_OK`。
- Docker 隔离库 `inventory_manual_inbound_test_20260730` 集成测试：13 项通过、0 失败；验证有项目领退、无项目领退、原单批次血缘、库存余额、服务端名称快照、错误项目拒绝、幂等重放与超量退回拦截。重复执行仍为 13 项通过。
- `inventory-vue3` 的 `npm run build` 通过，1821 模块；仅有原工程大 chunk 警告。
- 本地 `18091` 页面：新建院装耗材领用时“核销项目”不显示必填星号，默认“不关联核销项目”；选择一条耗材后“确认领用”启用。未点击最终确认领用，已退出页面草稿；真实落库路径由隔离库集成测试覆盖。
- `git diff --check` 通过；工作区其他任务的既有改动未触碰。

## 待验收

请产品经理在上述固定文件版本核对：无项目可领用、有项目仍可选择、领用/退回记录中的“未关联项目”显示，以及耗材明细仍为必填。验收后才按项目规则单独处理本地 commit；部署和 push 需另行授权。
