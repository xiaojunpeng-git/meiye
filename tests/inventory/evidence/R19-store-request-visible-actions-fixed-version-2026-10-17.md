# 第 19 轮：门店请货“转调拨 / 作废”固定候选

- 固定日期：2026-10-17
- 基线 HEAD：`0c587d2c0189576ec5fedc1ba313b6e8fa5f056b`
- 候选内容清单指纹：`5b4d61796464a8b36a6045af77aa80b65031f20f9c1547fc1a7a1fa1ef754b84`
- 状态：开发与自测完成，待产品经理对本固定候选确认验收。

## 本轮业务范围

1. 门店端请货列表按后端 `can_transfer` 显示“转调拨”，不再被平台端类型判断隐藏。
2. 门店作为请货方或供货方、且请货单状态允许并从未生成过调拨时，显示并可执行“作废”。
3. 门店从可见请货单发起转调拨时，服务端锁定原请货单的供货方、请货方、仓库和剩余商品；门店不能把总部仓供货改成门店出库，也不能增加原请货单以外的商品。
4. 门店发起的总部仓调拨只生成绑定原单的草稿并记录发起门店和操作人；实际发货继续由原供货主体执行。
5. 一旦该请货单生成过任何关联调拨，作废入口关闭，服务端也拒绝作废；已履约事实和库存流水不被修改。

## 源码、测试与注释

### 前端源码

- `前端代码/inventory-vue3/src/App.vue`
- `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue`

### 后端源码

- `后端代码/app/controller/store/product/inventory/InventoryStockRequest.php`
- `后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php`
- `后端代码/app/services/product/inventory/InventoryStockRequestServices.php`
- `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php`
- `后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php`

### 自动化测试

- `tests/inventory/js/store-request-visible-actions-contract.mjs`
- `tests/inventory/js/shared-document-layout-render.mjs`
- `tests/inventory/js/document-lifecycle-frontend-contract.mjs`
- `tests/inventory/php/request-transfer-lifecycle-integration.php`

### 生成物

- `前端代码/inventory-vue3/dist/` 仅作为本次构建验证产物，不纳入本轮源码提交清单。

本轮在调拨服务、请货查询与写服务、统一查询投影和前端调拨表单中补充或更新了职责、主体权限、原单方向锁定、剩余数量及审计边界注释。

## 文件 SHA-256

```text
c9393c6a4e78669e4e9f0175c1b13781025cb96eed117633cd04850f270a9a9c  后端代码/app/controller/store/product/inventory/InventoryStockRequest.php
55502e652f1d28f005a3a1ec101951c6adfb7818096a29164b1fc1344ce71ce9  后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php
c32dc38bca5fcdd90be13b64216d24c722f2623aae2e9ce69a9c099732d9ace9  后端代码/app/services/product/inventory/InventoryStockRequestServices.php
a188bfa7236159fe745456af99e567a3cf7870f11fa601fbf2f86872aa4c3054  后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php
6c56a03a36473791bc2125d4dd241e748e0ea27eb26df79169f92abcc18af5c3  后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php
417cb7088a19edb9b121b0f3bcb4d7a73282deafa584fd05daf613a4cd4d335f  前端代码/inventory-vue3/src/App.vue
919eb042d1a2d6b8637e5128f2177944e9fecfaefb9184c22a4f0ecaa7762ef5  前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue
152361f1afec907e7687a55f073fbd3b110badb17bb957abd67a473575cdb05d  tests/inventory/js/shared-document-layout-render.mjs
00a33d27aae5c8fe36bf21cb8afa0feb13003631ef70d980c7c5ceff02237e3e  tests/inventory/js/store-request-visible-actions-contract.mjs
d080f4a2852db2b1dc1edc5a4f37b1661ce7a7debfdd9fa2c7115ac16c77bd4f  tests/inventory/js/document-lifecycle-frontend-contract.mjs
0d7f91fc8b60e0f570fba8515bb2f29acaba8380228004ef717774ae8cadb562  tests/inventory/php/request-transfer-lifecycle-integration.php
```

## 自测结果

- 5 个修改后的 PHP 文件 `php -l`：通过。
- `npm run build`（`前端代码/inventory-vue3`）：通过；主资源 `index-de31ce07.js`、`index-70bc572b.css`。
- `store-request-visible-actions-contract.mjs`：通过。
- `shared-document-layout-render.mjs`：通过，真实共享模板确认门店端按能力显示“转调拨”。
- `document-lifecycle-frontend-contract.mjs`：4 项通过，确认门店/平台请求路径、能力字段和作废原因校验。
- `request-transfer-lifecycle-integration.php`：18 项通过；隔离库验证请货方作废、关联调拨后禁止作废、总部仓供货方向、商品映射、发起人审计和库存事实不被错误修改。
- `git diff --check`：通过。
- 本地服务：`18091` 与 `18081` 返回 HTTP 200；新浏览器会话停在登录页，未使用或猜测账号，未执行真实业务写入。

## 构建指纹

```text
70bc572b95d7dc7ddf680bb89f0d454128739c7ab98b2275fc127031d4a12654  dist/assets/index-70bc572b.css
c67e239662e94bced3b7f1becd61867fc96764a0e27caaaad0982c2e109bc837  dist/assets/index-de31ce07.js
f0686189910e5f323c8cb421719dda9b38e436cc81a409b2ba3f33d8ce78d4a8  dist/index.html
```

## 未执行

- 未连接或修改瑞昊生产数据库。
- 未在瑞昊线上创建调拨、作废请货或改变库存。
- 未 commit、未部署、未 push；这些步骤须在产品经理确认本固定候选后按项目门禁另行执行。
