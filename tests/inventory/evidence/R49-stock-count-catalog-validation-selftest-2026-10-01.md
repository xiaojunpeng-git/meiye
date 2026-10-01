# R49 盘点失效商品定位与高亮自测

## 范围与固定版本

- 基线 HEAD：`70ff540a8ca1a188130a056000d51c023602f9c6`。
- 业务范围：盘点确认前一次性核对全部商品/SKU 目录身份；有失效行时不产生单头、库存、批次或移动事实，端上一次显示全部问题行的行号与商品名并同时高亮。
- 权威数据源：服务端 `store_product` 与 `store_product_attr_value` 当前有效目录；草稿只保留用户当时的商品名称快照供提示。

| 分组 | 文件 | SHA-256 |
|---|---|---|
| 前端源码 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `2e9d17aee757e5fb79cc72bd4b4b1559a379fb4568a987b3397c4e51b3985f12` |
| 前端源码 | `前端代码/inventory-vue3/src/services/inventoryApi.js` | `963b9dca3fb9706768a6e486ef2071e06ec728e51d8775bb38a69935fd800dcf` |
| 前端源码 | `前端代码/inventory-vue3/src/utils/countSubmission.js` | `337436db4fcf8363d7eccd40cb81d480175f136b63d7a84b6b44bd7fd0a249d2` |
| 后端源码 | `后端代码/app/controller/store/product/inventory/InventoryStockCount.php` | `aaa1a1fcf27a6191053d9dfbb7465a707c8ada77daa59ac39bf90f34da166860` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountServices.php` | `bb96ef3b0fc0a0d92fdd09700784f12a2195e50656dce915a91690f2e895334b` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountCatalogException.php` | `348a0c10c11ae9e8fcc2751be70dd79a7cb26c7f3b7708fbc3d91db40ec0e7f5` |
| 自动化测试 | `tests/inventory/js/count-submission-contract.mjs` | `d5d142f6a19750edcbd5a7a9d9792f3c9ad1f302efb01512548b0434fcd479e7` |
| 自动化测试 | `tests/inventory/js/inventory-api-contract.mjs` | `0bdcc483982e860539d622fc747099c9c8f1d826980bef0a5ade071e20ce7fcf` |
| 自动化测试 | `tests/inventory/php/stock-count-catalog-validation-contract.php` | `d7bd09f3334ce11dac27b80d0496183f39140ffc68f6f5ad9dbaff57e757725b` |
| 自动化测试 | `tests/inventory/php/stock-count-catalog-validation-integration.php` | `648822f28dedcf4032718d6f22348c377595975c961f0b191b7044e29c6c16e0` |

数据库迁移、工程脚本：无。生成物：`inventory-vue3/dist/`、`cashier-v3/dist/`，不纳入源码清单。验收证据：本文件及下列命令输出。

## 自测结果

- PHP 语法：3 个后端业务文件、2 个新增 PHP 测试均通过。
- 后端静态合同：`STOCK_COUNT_CATALOG_VALIDATION_CONTRACT_OK`。
- 隔离测试库真实事务：`STOCK_COUNT_CATALOG_VALIDATION_INTEGRATION_OK`；两条已删除商品一次返回，行号为 1、2，单头和库存主体计数均为 0。
- 前端盘点行定位合同：`COUNT_SUBMISSION_CONTRACT_OK`，验证多行定位、草稿快照名称和明确处理语句。
- 库存 API 合同：59/59 通过，验证错误码与 `invalid_lines` 不会在请求层丢失。
- `inventory-vue3` 构建：1821 个模块通过。
- 门店端固定打包源 `cashier-v3` 集成构建：1986 个模块通过。
- `git diff --check`：通过。
- 旧 `stock-count-integration.php` 在重复使用的本地测试库上有 4 项固定幂等夹具残留冲突；其他 8 项通过。该脚本非可重复运行的清洁夹具，本轮以新增且可重复执行的专项集成测试覆盖失效目录原子回滚，不将历史夹具冲突冒充为本轮回归通过。

## 注释与已知业务阻塞

- 本轮在前端行定位工具、请求错误边界、服务端原子预检、异常契约和测试夹具中均补充或更新了职责、数据边界与不得绕过的关键约束注释。
- 瑞昊当前草稿 71 存在两条被删除目录：`601746 芦荟保湿洗面奶`、`200737 菁纯霜单支`。前者有另一个同名有效商品 `501374`，后者没有有效商品。完成真实盘点前必须由产品经理决定是保留实盘数（替换/恢复目录）还是删除两条盘点行；两种选择会改变最终库存事实，不由技术默认代选。
