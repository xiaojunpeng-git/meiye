# 库存单据明细备注本地自测

- 基线 HEAD：`74da07ef8c84480aac40c647a4fb4a6632a3fd3e`；本轮未 commit、push 或部署生产。
- 范围：入库、出库、院装、请货、调拨编辑表移除商品条码列、增加逐行“明细备注”；详情回显。盘点条码与扫码添加功能保持不变。
- 权威存储：手工入/出库写不可变批次移动事实，其他三类写原单据明细；旧行默认空。备注不参与库存数量、成本或统计运算。新字段最长 500 字符，跟随现有单据事务、权限和幂等边界。

## 文件与固定指纹

前端源码：`前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` `2919e98ba08e590160fe9bac40c2c936c0456ed5b484810d8e058c1ed7e25c17`。

后端源码（以下均在 `后端代码/app/services/product/inventory/`）：

| 文件 | SHA-256 |
| --- | --- |
| InventoryCrossSubjectTransferServices.php | `2ad7e2ecd61a446bc31a322f1c3dde99c381f8eb529fa5ac938c01023fc65785` |
| InventoryManualInboundReadServices.php | `47b48e94ce588270fb0813ce955c1fa5809f7b8b454b0e42261d22d89ed3023f` |
| InventoryManualInboundServices.php | `c52c154a32b821ce398a563669fcd09c9aad741bc423ff1ee29601ebd67bc452` |
| InventoryManualOutboundServices.php | `cdc7ae1c9b410d573ca2545280db11cc7b299b52cffc9cc20fbe2bf526088e96` |
| InventoryMovementQueryServices.php | `c90211822175ca1d6873ee6298791eac6f43047a7b5687437479f8eda71d3d23` |
| InventoryPlatformHqInboundServices.php | `deafaac3d5c00b35af8e70c1611b568e81455ad7c6953d993ed564353ad3dff9` |
| InventoryPlatformHqOutboundServices.php | `178f9d2a020a5cc3c75543e299bcbeb092506660bd9c6768276b470fcdf14045` |
| InventorySalonUsageServices.php | `7cda96faf7ef2ebfcc877db6789bb05cd428286852243992d3a5123966a49e5c` |
| InventoryStockRequestQueryServices.php | `9f787812e1897f4825e83b448980fc8ec25646be21a70360387bc9a16a0ce571` |
| InventoryStockRequestServices.php | `a64399c7af6e69287da9c407f855c36241ead9d44dc2c71374ccb392b119adfa` |
| completion/InventoryBatchMovementFactServices.php | `c72dc42d7001aa65d87fe9ce9aab3c7ba9ab3b58d9b7b29110eff9989e7c5294` |

数据库迁移（`后端代码/database/upgrades/2026-10-08-库存单据明细备注/`）：`00-升级清单.md` `675ad6c05177dacd7119535678c265772f4399a503f9dc9b8243c1021fe6b37a`，`01-正式升级.sql` `145d1855f26ac53909284c9c4dece0ae8e1bf4b6ce2fd482badd5640a1aef296`，`02-升级后验证.sql` `ab45223afb0f8212159cec12f6d306b4cf0c32e557956a15c8491013803edcc1`。

自动化测试：`tests/inventory/js/document-line-remark-contract.mjs` `214d958e7a4e7fddb3d8a56112aecac37824bc0bbf54beac5b2bd07eff63aaf6`；`tests/inventory/js/inventory-unit-column-contract.mjs` `93945b7e5984c1cf14213ace6a5241f4372a4cdeb7abfc0746e3f9da84a87154`；`tests/inventory/php/salon-usage-integration.php` `eb22a8d1471afabbffd7be50166a4314e858245bd58440ad1515ad3d57b0ff1a`。工程脚本与生成物：无纳入源码的改动。

本轮业务边界注释补在上述前端组件、手工入库、请货、调拨、院装服务及移动事实服务；测试中注明盘点条码保留和出库验证次序。

## 验证

- PHP 11 个改动服务 `php -l` 全通过；Vue 独立组件构建与 `cashier-v3` 集成构建通过；`git diff --check` 通过。
- `document-line-remark-contract.mjs`、`inventory-unit-column-contract.mjs`、日期/调拨/院装/操作栏等针对性前端契约通过；`read-model-projection-contract.php` 通过。
- 隔离库 `inventory_manual_inbound_test_20260730` 先备份四表至 `/tmp/inventory-line-remark-isolated-20261008.sql`，迁移执行及重放通过；院装集成测试 18 项通过，覆盖入库与院装详情回读、出库事实备注与重放、原有作废/多批次/失败回滚。
- 本机开发库 `ruihao` 四表备份 `/tmp/inventory-line-remark-local-ruihao-20261008.sql`（SHA-256 `42eaacb09072c7e13d5a90ec3d98cb55634460bdeb30ea3ed248e4118783723f`），迁移后四列均为 `varchar(500)`；`18091` 本地页面可访问。未操作瑞昊线上库。
- `run-frontend-contract.sh` 的 2 项既有导航/HQ 断言及 `request-dialog-contract.mjs` 的 2 项既有列表/扫码断言未通过，断言与本轮改动无关；其余已运行针对性测试通过。请货与调拨的新备注尚未做真实创建回读，不能以静态契约冒充该业务验证。

状态：产品经理已对上述固定版本确认测试通过，并明确授权本轮 commit、部署瑞昊、push；另行授权了瑞昊生产库四列迁移。正式部署仍须先完成目标实例的备份、回滚预检、迁移与线上验证；本记录不代表这些步骤已经执行。
