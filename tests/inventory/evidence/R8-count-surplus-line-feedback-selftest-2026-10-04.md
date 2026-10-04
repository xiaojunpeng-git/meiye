# R8 盘盈资料错误逐行定位自测

- 基线 HEAD：`2cbecf4b`；本轮未 commit、部署或 push。
- 范围：按商品总量盘点的正数实盘行在前端统一校验批号、单价、生产日期、到期日，不再以可能过期的草稿账面数决定是否必填；零实盘和未盘行免填。盘点确认仍由服务端在事务中锁定当前库存决定盘盈；若服务端仍拒绝资料不足行，附加提交明细的 1 基行号，门店和总部共用编辑器映射到草稿显示行及商品名并高亮。删除问题行后不误报目录删除。
- 业务边界：未调整盘点数量、批次、成本、事务、权限、幂等或草稿保存口径；没有连接远程实例或执行真实盘点。
- 自测：`count-submission-contract.mjs` 通过（正数且旧账面相等/较高、零实盘、批号 `000`、零单价、批次模式）；`inventory-api-contract.mjs` 60/60 通过；`count-physical-authority-contract.mjs` 通过；4 个改动 PHP 文件及集成脚本语法检查通过；`cashier-v3` Vite 构建通过（`index-bd8f5f82.js`）；`git diff --check` 通过。
- 限制：`stock-count-integration.php` 是写入型且已知在重复使用的夹具库中不幂等，本轮仅新增行号断言并做语法检查，未将其声称为集成通过；待产品经理在本地固定版本复测。
- 注释：已在 `InventoryStockCountLineException.php`、两个确认控制器、`InventoryStockCountServices.php`、`countSubmission.js`、`InventoryBusinessModal.vue` 补充/更新行号来源、正数总量必填、库存锁定口径、错误呈现和删行状态约束。

## 文件 SHA-256

```text
5ca6c44c05f6f863af393df7049c688c3e7b218e5e179ce5d7bda86a9839df1e  tests/inventory/js/count-submission-contract.mjs
6c110e9ae0b6cb61645e376906aa6f08ddf63e7ede9688931356eef68b9de761  tests/inventory/js/count-physical-authority-contract.mjs
7ae38fa7e883932af71b6554b39e42557a483d99f4d77de048556807b1f1a01f  tests/inventory/js/inventory-api-contract.mjs
5511bafd32ccdb230ddde75d041d59ec7417f4e74a6197ff6955ed94d33e6d83  tests/inventory/php/stock-count-integration.php
ca50a24036d546f41e22b8de0b36abf5821804d88d7f399cacef1601304ba173  前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue
6bc68a42d8076606e66b1157df0cbabae403f0c682125d807956d4ce7e6c8528  前端代码/inventory-vue3/src/utils/countSubmission.js
1dcbe19aa0b751d1332e70228660f21e2a4033b51358c6892d3fa1f83787daa8  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockCount.php
71b47aa4c5882d5e250efa483ce23dc4831c714f46d22729062f057ee376bd21  后端代码/app/controller/store/product/inventory/InventoryStockCount.php
991dce880376cca9c0dcad7b1daca6d30a4f8d8e99a1297412f357ed3907febb  后端代码/app/services/product/inventory/InventoryStockCountServices.php
183b28d90e93ae6b9b0c99d2568237620d0ac6b2f238fa1dd390ec1f863ca374  后端代码/app/services/product/inventory/InventoryStockCountLineException.php
```

- 本次正数实盘校验涉及的已跟踪文件补丁 SHA-256：`6847e262714e39226e16bf75ecb9ee9c9afa25f190afdc3dfd7b554efe610b57`；新增异常类和本证据文件为未跟踪文件，以文件 SHA-256 单独固定。
