# R38 补交记录与薪资分类自测

- 基线：`f53d6732cf9811e90a23dd2697506916e07ed466`；产品经理已确认本轮本地测试无问题，并授权本轮 commit、仅部署瑞昊及线上验证通过后 push。
- 订单中心补交记录：在欠款摘要右侧固定显示商品分类、商品名称；列表和导出共用后端投影。销售及历史补交从欠款明细读取商品历史名称、当前平台商品分类；充值补交显示“储值充值 / 未分类”。缺失或失效分类不按名称猜填。
- 补交销售人：首次分配时允许从同一补交单的实际业绩事实建立结构模板，独立核算组仍各自按本次补交金额分配。
- 员工薪资：补交业绩的单号、会员、门店和商品维度来自权威补交及业绩事实；销售欠款卡项按卡内实际项目的现行分类分摊，不用卡项外层分类。明细、汇总及分类下钻共用同一事实投影；不回写历史收款或业绩事实。
- 本地验证：`npm run build`、R38 补交合同、薪资报表服务合同、销售人分组合同、商品分类及薪资分类 MySQL 回滚测试、销售人首次分配回滚测试、薪资明细/汇总/导出集成测试均通过。涉及的数据库写入仅在本地测试事务内进行并回滚。
- 线上发布前只读核对：瑞昊独立库 `ruihao`，最新升级记录 `215 / 20261009-002-r32-unlinked-catalog-refund`；82 笔成功销售补交，82 条欠款商品明细中 76 条有商品分类编号。本轮不修改商品分类配置、数据库结构或历史业务数据。发布后的线上分类与薪资汇总仍须逐项复核。
- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue`。后端源码：`CashierV3MemberDebtProjectionServices.php`、`CashierV3OrderCenterRecordQueryServices.php`、`CashierV3OrderCenterUnifiedQueryContract.php`、`CashierV3SupplementSalespersonAdjustmentServices.php`、`StoreUnifiedReportPhaseSixServices.php`，均位于 `后端代码/app/services/` 对应模块。本轮复杂分类、金额分摊和首次分配边界已补充业务注释。
- 自动化测试：`tests/cashier-v3/php/` 下 `order-center-records-readonly.php`、`order-center-unified-export-contract.php`、`supplement-salesperson-independent-group-contract.php` 与三个 `r38-supplement-*.php`；`tests/report-phase-six/php/r38-supplement-salary-category-mysql.php`。
- 数据库迁移、工程脚本：无。生成物：`前端代码/cashier-v3/dist/`，仅作发布包，不作为源码提交。验收证据：本文件；线上发布结果另行记录。

## 固定文件指纹

以下 SHA-256 对应本轮源码与测试文件；已跟踪文件补丁指纹为 `5c029aadecc1313c276b03781e892b2bef099e5a8f0606284074361c29663577`。

| 文件（均相对 `美容源码/`） | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `b985fd6998faab29a4b3ce8f29dd42265fb7e4b574387e5e5fef1b36858c12bb` |
| `后端代码/app/services/cashier/v3/cashier/CashierV3MemberDebtProjectionServices.php` | `ad01bfaa22ed342b4a610ca311132a6289894d0be7f31be766db413aa252f945` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `7fe9d76213cd60d8ff26953af6b78db3a3d78902c32256d03ffb7b2b9ffa530b` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php` | `b12ce80f95098895a6ca8801f1beb89e545860d5f54841a9845e27b625461ffd` |
| `后端代码/app/services/cashier/v3/order/CashierV3SupplementSalespersonAdjustmentServices.php` | `590cf05dd00b8574c16aa87726dd246c9254608acdba8ce49c36c17c4e9185f9` |
| `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `5665aa5306c448de5c4e0ed2452d19fa629b8e134b33e38c832e8c338f3b2fed` |
| `tests/cashier-v3/php/order-center-records-readonly.php` | `6f51dc93dac8749c8e5a5af38cb1f83734c22ec88174de0520efa0b27e176969` |
| `tests/cashier-v3/php/order-center-unified-export-contract.php` | `0e93251a270be38869d604f07a7d9605fe0fe4546a38c3a63cc830ff99cd5c3d` |
| `tests/cashier-v3/php/supplement-salesperson-independent-group-contract.php` | `4c7cb27a8ab3e1fba13ec418ace6f416041fd9cdb1cded2aa73fb1b05ca9a7e0` |
| `tests/cashier-v3/php/r38-supplement-first-salesperson-mysql.php` | `4f5cc01cfddd14c11cb21688a734db26414a0a071ff46c3f53ad7909b0392cbd` |
| `tests/cashier-v3/php/r38-supplement-product-category-mysql.php` | `9dc6a7ce4f9d2de24f59fdc50994e5bc1d9a7116c0f7db6d98cb5517759e98c3` |
| `tests/cashier-v3/php/r38-supplement-product-name-contract.php` | `a4bd9d6ea9396aa05b517a416a41f8452b12fa7964d27ac18f9a00b523f13b5a` |
| `tests/report-phase-six/php/r38-supplement-salary-category-mysql.php` | `f7ca9c94b1ba6929a52cbf5f92d5d7f90c929e001ed15027f989a6bda926515e` |
