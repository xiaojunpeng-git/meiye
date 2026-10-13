# R14 入库、出库、院装选仓补充验收版本

本记录是 [R14 多仓固定清单](R14-fixed-manifest-2026-10-13.md) 后的补充版本；该旧清单中的下列文件指纹已失效，以本记录为准。共享工作区 HEAD（记录时）为 `221c9fc80d15e4c2a46a4fbdb53c68ef297a17b0`；本轮文件仍未提交，其他任务的修改不在本次验收范围内。

状态：本地开发与针对性自测完成，待产品经理确认验收；未 commit、未部署、未 push。18081 平台端开发源仍为 `前端代码/admin/`，嵌入的库存应用开发源为 `前端代码/inventory-vue3/`；此处的 Vite 构建不是 8080 平台集成包。

## 范围与结果

- 平台手工入库、出库在建单时选一个有效总部仓；选仓后清除旧仓已选商品，选品和提交都以新仓为准。
- 平台院装领用可选一个有效总部仓或授权门店仓；总部仓的领用、查询、详情、作废以实际 `location_id` 隔离，作废只回原批次。总部仓只可关联全局核销项目或不关联项目。
- 三个建单表单的选仓字段分别位于业务日期右侧；窄屏按单列顺序换行，其他业务表单不变。
- 院装列表显示领用仓库；旧单详情及作废使用单据原仓，不受当前列表筛选仓切换影响。门店端原有默认仓领用路径保留。
- 选仓只改变库存主体，不新建商品或改变商品归属；库存批次、结存、移动事实与单据在同一仓库口径下核对。

## 源码与测试指纹（SHA-256）

前端源码：

```text
2e0cecd9f8ef2691cdb2c19d13b5f368768cd1d2f953023f9aa26a16184155ba  前端代码/inventory-vue3/src/App.vue
6ad91ffc1e8eec544ab79b73a8cf6b57fd592ff0216e92eb60fd750ccfc8e4c8  前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue
f5698ec4edb0440108c3631a4fea244a634d6e28d5ba89d80a6ead416f109cb3  前端代码/inventory-vue3/src/documentDetailReader.js
8023d40a3e4c6e83841ee9c3ba3c7f494c8a38bb4e61e9f3d520ad42ee04f219  前端代码/inventory-vue3/src/services/inventoryApi.js
```

后端源码：

```text
2d3da708bb140894c978a76d07787faeeda7b4e034c62950b6e0a1515c5e7d91  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformSalonUsage.php
388edf24615d4b657644c02839fe588f6f444e7a51e484d961f30587220eaffc  后端代码/app/services/product/inventory/InventorySalonUsageServices.php
d16cde1721b9685bbff4fa8ea54d2a4b6693668e0ad5d2f69d8519fdc117c3bd  后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php
```

自动化测试：

```text
e4f3dd45c6a358de10da8c3252e27da091778c5a836bf142b6856e3952c76cc5  tests/inventory/js/hq-multi-warehouse-contract.mjs
108e00de28382ce8174712fc9c33c9f68d863a74964dfb10ecda939889685395  tests/inventory/php/hq-multi-warehouse-integration.php
531854fbac85bf55ff47a009e467d637f1558c6e21b8744847a240cab1aba978  tests/inventory/php/salon-usage-project-selector-contract.php
7cd480e0bd4165540675fd793ee798605206a6e85193692be5b8803052df336c  tests/inventory/php/read-model-projection-contract.php
```

数据库迁移：本次补充无新增迁移；沿用 R14 多仓迁移正本，不触及远程实例。工程脚本：本次补充无修改。生成物：本地 `inventory-vue3/dist/`，不作为源码或正式部署结果。

## 自测

- `npm run build`（inventory-vue3）：通过；Vite 仅提示既有大 bundle 警告。生成 JS `index-c08b676d.js`，SHA-256 `4b44ba255bf9352a8aaa83a8fb692f419dc907c0401357476f252e1759eb9231`。
- 隔离 MySQL 库 `inventory_manual_inbound_test_20260730` 的 `hq-multi-warehouse-integration.php`：39 条断言通过、0 失败；使用事务回滚，未连接线上业务库。
- `hq-multi-warehouse-contract.mjs`：18 条前端传输契约通过、0 失败；另有院装可选项目、项目权限、错误文案、读模型合同测试均通过。
- 受影响的 3 个 PHP 文件 `php -l` 通过；`git diff --check` 通过。
- 浏览器自动化可读到 18081 的已认证库存 iframe，但点击嵌套 iframe 控件时驱动报告目标失效；未把此项算作真实页面点击验收。产品经理仍需在 18081 逐项试选仓、建单、查询与作废。

本次新增或更新职责、仓库授权、原仓读取与冲销边界的注释位于 `InventorySalonUsageServices.php`、`InventoryPlatformSalonUsage.php`、`InventoryBusinessModal.vue`、`documentDetailReader.js` 以及对应合同测试。验收期间任一指纹变化，须重新固定受影响范围。
