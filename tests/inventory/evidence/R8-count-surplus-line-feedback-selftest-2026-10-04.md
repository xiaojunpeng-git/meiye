# R8 盘盈资料错误逐行定位自测

- 基线 HEAD：`2cbecf4b`；功能提交 `12a9613f`。发布前 PHP 7.4 兼容修正单独归档；本记录生成时尚未部署或 push。
- 范围：按商品总量盘点的正数实盘行在前端统一校验批号、单价、生产日期、到期日，不再以可能过期的草稿账面数决定是否必填；零实盘和未盘行免填。盘点确认仍由服务端在事务中锁定当前库存决定盘盈；若服务端仍拒绝资料不足行，附加提交明细的 1 基行号，门店和总部共用编辑器映射到草稿显示行及商品名并高亮。删除问题行后不误报目录删除。
- 业务边界：未调整盘点数量、批次、成本、事务、权限、幂等或草稿保存口径；未执行真实盘点或写入远程业务数据。
- 自测：`count-submission-contract.mjs` 通过（正数且旧账面相等/较高、零实盘、批号 `000`、零单价、批次模式）；`inventory-api-contract.mjs` 60/60 通过；`count-physical-authority-contract.mjs` 通过；4 个改动 PHP 文件由瑞昊 PHP 7.4.33 逐个只读语法检查通过，集成脚本本地语法检查通过；`cashier-v3` Vite 构建通过。独立从提交 `12a9613f` 构建得到 `index-2678e7b9.js`，旧提交 `ac285522` 隔离构建指纹与瑞昊现有前端完全一致；`git diff --check` 通过。
- 限制：`stock-count-integration.php` 是写入型且已知在重复使用的夹具库中不幂等，本轮仅新增行号断言并做语法检查，未将其声称为集成通过。产品经理已确认本地固定版本验收通过，并对 PHP 7.4 兼容修正补验确认。
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
c5aa34294484f264b3d234701565c27d7c5a4e1dc78f35b8c18cf2c10e3908a5  后端代码/app/services/product/inventory/InventoryStockCountLineException.php
```

- 正数实盘校验四个前端/测试文件相对基线的补丁 SHA-256：`6847e262714e39226e16bf75ecb9ee9c9afa25f190afdc3dfd7b554efe610b57`；异常类的最终文件 SHA-256 如上。
- 发布前兼容修正：原异常类构造器使用 PHP 8 属性提升；瑞昊为 PHP 7.4，已改为显式属性和赋值。产品经理对仅此语法兼容修正补验确认；不改变盘点规则、接口、页面或库存数据。
