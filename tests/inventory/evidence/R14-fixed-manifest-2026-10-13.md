# R14 固定验收版本清单

版本：R14-HQ-MULTI-WAREHOUSE-20261013-ef3ba32c。2026-10-13 移除库存页仓库设置入口后重新固定。

开工基线 HEAD：ab1de28331d71d6ee103efeb063ae98a7adfbc4f。
固定时共享工作区 HEAD：648261e2c95995309093389df3cf25e751bf01f6。
其他任务推进 HEAD 不等于本轮已提交；两个 HEAD 间本轮库存源码无差异。

文件清单 SHA-256 聚合指纹：ef3ba32c1b9da1d2fb6341b547ee2ad0f794bce99c2d3394718439323cda58d3。对下面 40 个源码/迁移/测试路径执行 `shasum -a 256`，按路径排序后再做 SHA-256。
已跟踪文件相对开工基线的 binary diff SHA-256：ee10382bc8f0af4824e77f3180086afe114b039252d900cd244c506e167cac6f。
新文件不在 git diff 中，以下逐文件校验包含全部新迁移/测试，聚合指纹以完整文件清单为准。

状态：本地开发与针对性自测完成，待产品经理验收；未 commit、未部署、未 push。

## 前端源码（8）

- [前端代码/admin/src/pages/stockManage/InventoryV3Bridge.vue](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/admin/src/pages/stockManage/InventoryV3Bridge.vue)
- [前端代码/admin/src/router/modules/stockManage.js](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/admin/src/router/modules/stockManage.js)
- [前端代码/admin/src/store/modules/admin/modules/menu.js](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/admin/src/store/modules/admin/modules/menu.js)
- [前端代码/inventory-vue3/src/App.vue](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/inventory-vue3/src/App.vue)
- [前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue)
- [前端代码/inventory-vue3/src/documentDetailReader.js](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/inventory-vue3/src/documentDetailReader.js)
- [前端代码/inventory-vue3/src/services/inventoryApi.js](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/inventory-vue3/src/services/inventoryApi.js)
- [前端代码/inventory-vue3/src/styles.css](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/前端代码/inventory-vue3/src/styles.css)

## 后端源码（22）

- [后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformExcelImport.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformExcelImport.php)
- [后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCatalog.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCatalog.php)
- [后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCrossTransfer.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCrossTransfer.php)
- [后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockRequest.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockRequest.php)
- [后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php)
- [后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php)
- [后端代码/app/controller/store/product/inventory/InventoryStockRequest.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/controller/store/product/inventory/InventoryStockRequest.php)
- [后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php)
- [后端代码/app/services/product/inventory/InventoryHqLocationServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/InventoryHqLocationServices.php)
- [后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php)
- [后端代码/app/services/product/inventory/InventoryPlatformHqCatalogServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/InventoryPlatformHqCatalogServices.php)
- [后端代码/app/services/product/inventory/InventoryPlatformWarehouseCommandServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/InventoryPlatformWarehouseCommandServices.php)
- [后端代码/app/services/product/inventory/InventoryPlatformWarehouseServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/InventoryPlatformWarehouseServices.php)
- [后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php)
- [后端代码/app/services/product/inventory/InventoryStockRequestServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/InventoryStockRequestServices.php)
- [后端代码/app/services/product/inventory/InventoryV3ExcelImportServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/InventoryV3ExcelImportServices.php)
- [后端代码/app/services/product/inventory/completion/InventoryBatchMovementFactServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/completion/InventoryBatchMovementFactServices.php)
- [后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryContract.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryContract.php)
- [后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryWorkerContextResolver.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryWorkerContextResolver.php)
- [后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php)
- [后端代码/app/services/product/inventory/query/InventoryProductStockSummaryServices.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/app/services/product/inventory/query/InventoryProductStockSummaryServices.php)
- [后端代码/route/admin.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/route/admin.php)

## 数据库迁移（4）

- [后端代码/database/upgrades/2026-10-13-库存V3总部多仓/00-升级清单.md](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/database/upgrades/2026-10-13-库存V3总部多仓/00-升级清单.md)
- [后端代码/database/upgrades/2026-10-13-库存V3总部多仓/01-升级前检查.sql](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/database/upgrades/2026-10-13-库存V3总部多仓/01-升级前检查.sql)
- [后端代码/database/upgrades/2026-10-13-库存V3总部多仓/02-正式升级.sql](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/database/upgrades/2026-10-13-库存V3总部多仓/02-正式升级.sql)
- [后端代码/database/upgrades/2026-10-13-库存V3总部多仓/03-升级后检查.sql](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/后端代码/database/upgrades/2026-10-13-库存V3总部多仓/03-升级后检查.sql)

## 自动化测试（6）

- [tests/inventory/js/store-menu-contract.mjs](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/tests/inventory/js/store-menu-contract.mjs)
- [tests/inventory/php/multi-warehouse-rollout-contract.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/tests/inventory/php/multi-warehouse-rollout-contract.php)
- [tests/inventory/php/unified-query-registration-contract.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/tests/inventory/php/unified-query-registration-contract.php)
- [tests/inventory/js/hq-multi-warehouse-contract.mjs](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/tests/inventory/js/hq-multi-warehouse-contract.mjs)
- [tests/inventory/php/hq-multi-warehouse-integration.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/tests/inventory/php/hq-multi-warehouse-integration.php)
- [tests/inventory/php/r14-three-warehouse-live-local.php](/Users/xiaojunpeng/Desktop/魔核/美业/美容源码/tests/inventory/php/r14-three-warehouse-live-local.php)

## 工程脚本

本轮无新增或修改工程脚本，不包含其他任务的启动/部署脚本。

## 源码、迁移和测试校验

```text
966b2b6c2ab1419eedc309d67e65ae60ecb98e2a675bf7157427326c78e91863  tests/inventory/js/store-menu-contract.mjs
3d322d6f7180b2f79df655d4b4887b1624961fd3be429397abdcbffbdd31c806  tests/inventory/php/multi-warehouse-rollout-contract.php
3e846e98b52844ebeb96cf03a7c9a093f8baf91d34bd5ed44a41cea9399b339e  tests/inventory/php/unified-query-registration-contract.php
70ad5115303c5a8cef3ae8df11efd55ceccaaa6220d2783996cbde30541df191  前端代码/admin/src/pages/stockManage/InventoryV3Bridge.vue
487170018b24a63f88ee56fd99ba3276c41aa2a7f84e46d1742e18729bcf81a9  前端代码/admin/src/router/modules/stockManage.js
dce0daa43f220c98fec4a933a047a0f5595f2a638fc3f99d4f17ba35580f069b  前端代码/admin/src/store/modules/admin/modules/menu.js
9354812477c1b1937347cb252fbb1fee06977b2d9c93361b775aa391640ce943  前端代码/inventory-vue3/src/App.vue
726d184bd8432f3042db81ce3c5247965dbdcc92750ed35a258c87bc67fb5a11  前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue
32d536d43c2ee35d8d63645bf2c97d7c84a9da2a837c17d37c76481d2802bd06  前端代码/inventory-vue3/src/documentDetailReader.js
039e58515d0cb39633bd1a69a085aca2534bb1d2dc7a4d47dd655a633e1379b3  前端代码/inventory-vue3/src/services/inventoryApi.js
c2aec9692edede9659501d506c6135fce9c11c9c8f3b7d9d665e3008cd7d7334  前端代码/inventory-vue3/src/styles.css
357c4e4a34167a7d09bbec094e74b43aed8d76cc156ecd07a2bcd4a9a041ffb5  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformExcelImport.php
87dde3151e675a58b39f8bcd6392d0f4b8a2399a87b65e16baeb902981a615ce  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCatalog.php
f256596ceb2c14c0acb343bdf3ac8d8c2fa6c31d02a8a65c287ea1826f48a1a1  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqCrossTransfer.php
fdc8aa462e72dc3ad97a2442829a76f7670be0c0fd73e5000e7208523c53babd  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformHqStockRequest.php
ffc50937c530c21337f44ba336185fd647619b5dd1e2c8f8a47eb2772880f17a  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php
abe833910ae66d7e20e1da61aaa09ee578bdf05f428461b121d04e1df4ca6d50  后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php
de420a2e94c1e545913d20b7e780b999adad7aa8d0f3d24eab10cc58e7a2f4ec  后端代码/app/controller/store/product/inventory/InventoryStockRequest.php
95828aa78dd44246f52a691a79bc6b598c44ac49d3b6661d88557ea4d95e52f8  后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php
3cfead73166df4b9ba1b7b055222edb5d5d5c7d2c704e5ff054bb53b52ea4493  后端代码/app/services/product/inventory/InventoryHqLocationServices.php
a52e84732a0162aa19d09c9ffa3dd248265253c098d84bc5923126dc61400ffd  后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php
dbbb8a4cea81e9607827e18afe49fdf6a13b4e25435e88503aac34531dad084c  后端代码/app/services/product/inventory/InventoryPlatformHqCatalogServices.php
2fa6afc661721471763e378d395166832986ea9084c1472c0586c8abdea19bac  后端代码/app/services/product/inventory/InventoryPlatformWarehouseCommandServices.php
f43f8358c57cbf2503b91b6adf0c86c1f6b4a0173b9b23c42eec014add343b1b  后端代码/app/services/product/inventory/InventoryPlatformWarehouseServices.php
948503bb54ebed71754890714af99189fb482106cb6e4f5666b3ac0b42a3e878  后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php
1edabc735294fb51cc693e91afb5f6b155120f9fe95d702219038a4380600d39  后端代码/app/services/product/inventory/InventoryStockRequestServices.php
5f28447396cc9d81d3e1cdc4a9a3b8bc1a965836ffe0cf5bda1ae20e0495469c  后端代码/app/services/product/inventory/InventoryV3ExcelImportServices.php
907d7596c5f7cd84ef2656542364a51773b2f841160ec33a3eb3174ab95e82cc  后端代码/app/services/product/inventory/completion/InventoryBatchMovementFactServices.php
64755e2abf35d9c708d7d428ae95a4304608cb7ceb7df7094f5550d248d63d76  后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryContract.php
4e46f24f24a3a0ca1e6cf872e781f1831e2b448efbfeac52785cd6498f3753bd  后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryWorkerContextResolver.php
a58842e536023babef1463038ff42bd42065f7f188f54d63df743a8006747f42  后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php
f00428ff343732f7668776e142dded62ff82ae1f62c57df0c083804f138d01d8  后端代码/app/services/product/inventory/query/InventoryProductStockSummaryServices.php
353074902ba6b9d141f01ba3fabe9925c6bd7bd8b67ab613d1df1990fe2aefd3  后端代码/route/admin.php
e5aa719eb446e5a52750b8c918c33085ca1e2f2208066191dc7c8ccacd5601c8  tests/inventory/js/hq-multi-warehouse-contract.mjs
332cbaed51c508942c50236f895919f3128c591dc15142429eb7b5283465a644  tests/inventory/php/hq-multi-warehouse-integration.php
ce4f694a3a2ea5020026496a6669c99eff01dc025b3b576c24cc613626cd3499  tests/inventory/php/r14-three-warehouse-live-local.php
a55f72a99eaaa76ccc99cbc458f877d669e0c27e5951aa78c3c581cdfae968b5  后端代码/database/upgrades/2026-10-13-库存V3总部多仓/00-升级清单.md
0a41a8e0129a2a57763dc4d05657cea52a168c3cc2009e6fbd6070d447285be8  后端代码/database/upgrades/2026-10-13-库存V3总部多仓/01-升级前检查.sql
dc70b8970a7b090e243c68f92722b6d14c6972e2c336c0686b3fc21859613f78  后端代码/database/upgrades/2026-10-13-库存V3总部多仓/02-正式升级.sql
0a27d796b992148c445232a06afa5a1ecfe39171b65968d4fde6214b6c88aa16  后端代码/database/upgrades/2026-10-13-库存V3总部多仓/03-升级后检查.sql
```

## 生成物

只记录 canonical inventory-vue3 当前源码本地 Vite 编译结果；不是 8080 集成包，没有正式部署。

```text
a162151d4058a407f67c57f45175971d3eb0bec2acd3ccecdd9aa9a099a68b23  前端代码/inventory-vue3/dist/index.html
6a5686a937ffcd7f435aa33e6e153c60063e8b845f0cccb75eb380b12e4c5f9b  前端代码/inventory-vue3/dist/assets/index-c655c681.js
eef41005b03aa907839179c758afecd88823558f9e95c7ab7908f7c72b6db930  前端代码/inventory-vue3/dist/assets/index-eef41005.css
```

## 验收证据

旧浏览器截图的临时仓 141 已清理；本次新增的 142/143/144 三仓、测试批次和两笔已收货单据保留在本机供验收。详细流水与对账见 R14-three-warehouse-live-2026-10-13.md。
证据不混入业务源码清单；本清单自身不纳入自身 SHA，以免递归。

```text
39ba29ec480c1871c1a52e1cea90025646b3ea2bd17611a5065bd132c764f75d  tests/inventory/evidence/R14-selftest-2026-10-13.md
6d337eb9bae54ec2001da04cd1754db40e0aed474c2ee3c5d5449d24fa7d89f8  tests/inventory/evidence/R14-progress-2026-10-13.md
d6ee4d5575987a28031e5e96fa1f68d0221c2710702e3a1fefb74aada67a4b84  tests/inventory/evidence/R14-test-results-2026-10-13.json
2902730cfe7e949859d89ab6d7dd62f8d5b70e5263178110e88ccd1309f3994d  tests/inventory/evidence/R14-count-original-warehouse-desktop.jpg
8f8f208bcaa7ea55243cf813cedbe2f11ff0c1c2ceaddc3fed39d7541e565a0b  tests/inventory/evidence/R14-count-warehouse-picker-desktop.jpg
26283c57e6f07f73fbb7ee679d19b84ca4b27cba54215359919548abee2ed830  tests/inventory/evidence/R14-count-warehouse-picker-narrow.jpg
f7c15d55c7f07755d49def1c8c79c8c808daeb5ed53a2f0ae17d59b1e41c2d3f  tests/inventory/evidence/R14-request-supplier-warehouse-desktop.jpg
f4baf6b3129dbac840284c95e35f4f316b6c7b6c67fac1150222540ec8cd8389  tests/inventory/evidence/R14-warehouse-settings-desktop.jpg
d4dd0ef9dbe2606201765866e07b3d8940e9b609c72b951c959f78eb68fe89bf  tests/inventory/evidence/R14-warehouse-edit-desktop.jpg
3514d6f161d093e2fc566d9b1c4099bb0626601f9149680c794ab0d9a0c65dd3  tests/inventory/evidence/R14-three-warehouse-live-2026-10-13.md
89748520beebb9c8d41f14b8374abc82e8dd4d1590f7918aeabc4f10b9949efb  tests/inventory/evidence/R14-three-test-warehouses-2026-10-13.jpg
bf990d9b2e0947bb5adc95b35b7e7b2fa9a21bbb1d488f2cc6bdc2b7ae605718  tests/inventory/evidence/R14-three-warehouse-A-stock-2026-10-13.jpg
4510bc63697691adcf2416772d681ec1810a87b808f608876334bc2024eeb535  tests/inventory/evidence/R14-three-warehouse-B-stock-2026-10-13.jpg
fa1f1a94383271076d1d731c488062bbe501af73b529b2712bf3b566ee3010d7  tests/inventory/evidence/R14-three-warehouse-C-stock-2026-10-13.jpg
19746736614aa584fd98648803a75cbee8ae1acfd693d3dee176608136648797  tests/inventory/evidence/R14-three-warehouse-transfer-list-2026-10-13.jpg
996aa7b4a761a028b186aa59605ce11dbce5669427813ddb3ce9d9ae37568ad1  tests/inventory/evidence/R14-three-warehouse-transfer-detail-2026-10-13.jpg
2ece29579f080d76ab4af7f8e7c62d2b3fb7f24ec9c6fd6cd8bd78999b89732b  tests/inventory/evidence/R14-warehouse-entry-removal-2026-10-13.md
de8abdc486b964b690091d36cf4ca5261d9c067d9c58853c80bd7a61bdc096f2  tests/inventory/evidence/R14-warehouse-entry-removed-transfer-2026-10-13.jpg
```

## 交付边界

原 30 项隔离回归与旧合同失败明细见 R14-selftest-2026-10-13.md；本机持久三仓实测与 33 项补验见 R14-three-warehouse-live-2026-10-13.md。测试仓外库存/数量保持 7477/217666；测试仓内新增 3 行/3 件，新增 5 条移动事实。
只提交本清单对应的已验收闭环文件，禁止夹带共享工作区其他变更或已暂存文件；验收期间本轮文件如变化，应重新固定并补验受影响范围。
