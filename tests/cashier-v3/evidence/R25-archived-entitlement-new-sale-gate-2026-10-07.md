# R25 已购权益履约与新销售下架拦截自测

- 基线 HEAD：`1cd5a04803f224bb3eade21f7b4a7b046de9f0f5`。
- 固定文件清单 SHA-256 汇总指纹：`c9c73c9a84d3c21572b61d83a34df0c1be2728e47c1f7ed6f6c0236908202239`；按下列顺序对每个文件执行 `shasum -a 256`，再对输出执行一次 SHA-256。
- 已跟踪文件补丁指纹（仅下列闭环文件的 `git diff --binary`）：`f1b17cd7f5777348ac45f525870f5fb1aa39406b7289feb533c330ae8ad2377f`；新测试文件的 SHA-256 见下表。
- 前端源码：无。数据库迁移：无。工程脚本：无。生成物：无。
- 后端源码：`后端代码/app/services/product/inventory/completion/InventoryEntitlementCompletionDiscovery.php`、`InventoryEntitlementCompletionProvider.php`（历史项目软删除后仍按实际库存策略履约）；`后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php`、`ThinkPhpCashierV3SaleCatalogAuthority.php`、`后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php`（新销售下架拦截和具名提示）；`CashierV3CheckoutPreparationServices.php`、`CashierV3SaleInventorySettlementServices.php`、`后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php`（同步更新业务边界注释）。
- 自动化测试：`tests/cashier-v3/php/sale-off-shelf-contract.php`、`tests/cashier-v3/php/checkout-snapshot-direct-authority-contract.php`、`tests/cashier-v3-checkout-submission-execution/php/mysql-integration.php`。
- 本次注释补充/更新文件：上述全部后端源码文件；说明已购权益与新销售分离、门店范围内的商品名称、最终提交加锁检查，以及快照价格/卡配置不被目录变化重算。

| 固定文件（相对源码正本） | SHA-256 |
| --- | --- |
| `后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php` | `78d684931a8d727e0ac00d0dffabfb0cc4670ad53cd945b5f161663f8b142c01` |
| `后端代码/app/services/cashier/v3/cashier/ThinkPhpCashierV3SaleCatalogAuthority.php` | `d2e123f7c5f15d30dbaef850ef8eb4f74a58c6c4f5811c50231fe5b17d381087` |
| `后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php` | `3f9b2368f68047eadaeb26e4138ea3b612dc8f3793478bc061ce3285bdc6b7e5` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php` | `dbb76c55f6ea4ae2ad1943be4f4b128fc13cbc2a631bff72fa7175ef5654b468` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3SaleInventorySettlementServices.php` | `bb198b10a5627804be951a9dd6dfcebb8baa513f7f360877e0e6ec10cada5d10` |
| `后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php` | `d9ef19a5472d4329d3b02b3895f816f3c857d3a350562f68e39cb97a9dcefdc1` |
| `后端代码/app/services/product/inventory/completion/InventoryEntitlementCompletionDiscovery.php` | `2e616f00a079dc00c971526fa0cbc1fd33315ab92930f2ee7b6808320e15e299` |
| `后端代码/app/services/product/inventory/completion/InventoryEntitlementCompletionProvider.php` | `058d1c2a207d3d1ca86b177161cece44be6f4b19ccb72a2864701a1194c247a6` |
| `tests/cashier-v3/php/checkout-snapshot-direct-authority-contract.php` | `aa4c8809c02d8a3fdf956997fae41bd3f12d8eed8ec85db2987504d4166e2126` |
| `tests/cashier-v3-checkout-submission-execution/php/mysql-integration.php` | `16eb982f832362b49f95a4cc2310fb1c2cf27af6e6d60017935b615c210b1b2e` |
| `tests/cashier-v3/php/sale-off-shelf-contract.php` | `243b8451fea5749e499c3405dc3a364e40c5ae12be5d337601f01d7f9fd69b62` |

## 验证

- `sale-off-shelf-contract.php`：12/12 通过；覆盖软删除、商品下架、规格下架、在售商品、待审核不可售、下架与待审核并存时优先具名提示，以及无门店范围名称时不泄露商品名。
- 本地瑞昊数据副本只读查询：已软删除商品返回具名下架提示；最终提交守卫对该商品的新销售行拦截、对已购权益行放行；在售商品的新销售行放行。没有发起真实结账或写入业务数据。
- `tests/inventory/php/completion-contract.php`：48/48；`tests/inventory/php/gateway-adapter-contract.php`：8/8；`tests/cashier-v3/php/card-purchase-issuance-contract.php`：22/22；`tests/cashier-v3-checkout-submission-execution/run-all.sh`：静态 16/16，通过（隔离 MySQL 默认跳过，另有原有 `friendCountsAsCustomer` 警告）。
- `tests/cashier-v3/php/checkout-snapshot-direct-authority-contract.php`：本轮新增断言通过，全文件 46 通过、4 条既有人员/权益选择断言失败；`tests/cashier-v3-sale-only-submission/php/preparation-identity-contract.php`：4 通过、2 条既有错误原因断言失败。两个失败范围与本轮逻辑无关。
- 旧隔离 MySQL 结账用例的数据库缺少当前结账生命周期表及其依赖，新增的两条历史项目场景尚未在该旧夹具中完成执行。未以静态测试代替端到端验收。
- PHP 语法、`git diff --check` 通过；重启本地 `mohe-cashier-app` 后，`http://127.0.0.1:18091/view_cashier_v3/` 返回 HTTP 200。

状态：开发与自测完成，待产品经理对本固定文件版本确认验收。未 commit、部署或 push。
