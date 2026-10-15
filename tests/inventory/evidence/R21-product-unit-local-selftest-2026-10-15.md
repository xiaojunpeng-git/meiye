# R21 商品库存单位本地自测

- 基线 HEAD：`04384f451a7f9e8c47508137534db793dfef1e9a`。工作区存在其他任务改动，本轮仅固定下表文件。
- 问题：普通商品资料包装单位为“包”，旧 SKU/库存主体单位可能仍为“元”；入出库选品与库存查询原先各读不同字段。
- 口径：普通商品优先当前商品资料 `unit_name`；开启院装耗材库存的商品优先 SKU `stock_unit`；仅空值时回退另一字段。失效/孤立商品没有当前目录时保留库存主体原单位，不按名称猜测。单位标签不参与库存数量和金额换算。

## 固定文件

| 分组 | 文件 | SHA-256 |
|---|---|---|
| 后端源码 | `后端代码/app/services/product/inventory/InventoryProductUnitPolicy.php` | `716ea100d0fd02bcbf6a7fa33efea8dc0b1e315938f85a068e2acf60af679f36` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryCurrentStockUnitProjection.php` | `72179e25dba8ee37c82d99c111e814faee8e2f602e458023aac8e051f8a342b6` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryBatchTransferServices.php` | `d8143fe55c7388f2eebb6758a3cebc479a73e3f8f81e20cef8bbf4d36f0a2522` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php` | `466f839d4bb34f62c2f1d3dc6a53eeeda78cf12231407f63faa5b4ceefe024bd` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryManualInboundServices.php` | `88cfbc8e1182e0f002d821d86d0fd55d7961cf393d18d389c3bf9b23d061cf1d` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryMovementAnalyticsServices.php` | `4752690522daa8df2b3c0f9317d3e555e07e3aaaa98b7f38072c314b19710b80` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryPlatformHqCatalogServices.php` | `786d673dbc2d1c5e020b7423ccf46a2eb594a246641f04a128b0c12025ea0706` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountServices.php` | `8add6dfbd99a5218b3af7c07da95b4687c2e0f358fbda700893e76d800ce94ed` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockRequestServices.php` | `9f50470d2ab7f8855b833b20346494796e664ec09b312020a7c736239c9af074` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStoreCatalogServices.php` | `505ec16a3d360ff29588458022ae6cc261b5cfb82216f0ed7c3436b40cf81966` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryV3ExcelImportServices.php` | `2cac4a05e76e975cb9f12e6b4e1aeb49145b4b0342c2d47b3f2428fa60811576` |
| 后端源码 | `后端代码/app/services/product/inventory/StoreStockCrossSkuServices.php` | `0d9aafd6927469cbfa4e5650f4ea6cd3ce33f9f3bf6575a0a641f5f0ee281db0` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryBatchStockQueryProvider.php` | `114a3d2b32aaf96666224a31b430f8f78edc4d8c04677feab4da4dbcc669806e` |
| 后端源码 | `后端代码/app/services/product/product/StoreProductServices.php` | `b20bbfe72fd71ff44c066433230eb88aeb321878f27164257d5b27471fc1fef4` |
| 后端源码 | `后端代码/app/services/product/sku/StoreProductAttrValueServices.php` | `0f4a6b313e281a2b4d40bc70b8872e05eb3d2af4e51d15e546409b7de921acbe` |
| 自动化测试 | `tests/inventory/php/product-unit-policy-contract.php` | `3c8c90dd3c8deb3296fe58b5b79bf1b655eeb66fe6408e3380a23d2acdf5549e` |

已跟踪文件补丁 SHA-256：`776351eda7eb49ef3da51344a74966fc5b36e4ea9e20bf3687dc038221eb467e`。前端源码、数据库迁移、工程脚本和生成物无本轮变更。此证据文件本身不计入上述源码补丁指纹。

## 自测

- `product-unit-policy-contract.php`：25 项通过，覆盖普通/院装优先级、空值、目录 SQL、跨主体目录、当前库存投影、旧平台库存明细及不同旧单位不拆统计行。
- `catalog-scan-contract.php`、`store-outbound-catalog-wiring-contract.php`、`stock-summary-total-contract.php`、`statistics-period-aggregation-contract.php`、`excel-import-contract.php`、`cross-subject-transfer-contract.php`、`stock-count-catalog-validation-contract.php`、`read-model-projection-contract.php` 通过；改动 PHP 文件语法和 `git diff --check` 通过。
- 本地 `18091` 门店页面，扬州欧尚店：重新查询“一次性袋子”，库存单位“包”、数量 5；新建入库和新建出库的商品选择框搜索同一商品均显示“包”、库存 5。只读查看，未保存单据。首次页面保留的旧查询结果仍显示“元”，重新查询后由热更新后端返回“包”。
- 既有 `request-transfer-lifecycle-contract.php` 有 1 项失败：旧断言要求调拨撤回入口全部禁用，与此前已上线的“发货后未收货可撤回、撤回后草稿可作废”能力冲突；本轮未修改该测试或撤回能力。

## 数据架构与边界

- 权威单位来源为商品资料包装单位及院装 SKU 耗材单位；新入库、盘盈、请货、调拨写入时仍由服务端在原事务和授权范围内重新读取。当前库存、批次、首页及统计只装饰已授权查询结果；数量、成本、事实粒度、业务日期、冲销和合计口径不变。统计不再用旧单位文本拆分同一 SKU。修改处已补充说明来源、范围和不改事实的业务注释。
- 不更新 `inventory_stock.stock_unit`、历史单据快照或已结算事实；历史明细仍可能显示原“元”。失效商品没有可核实的当前资料时也不猜单位。若要追溯更正历史凭证，须先单独确认历史口径并核对原始资料。
- 未做生产库写入、提交、打包、部署或 push；本地自测不能替代产品经理对固定版本的验收。

状态：本地开发和自测完成，待产品经理确认验收。
