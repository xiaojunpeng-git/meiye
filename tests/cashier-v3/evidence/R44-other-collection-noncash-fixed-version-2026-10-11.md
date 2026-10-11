# R44 其他收款非现金结算与退款限制固定版本（2026-10-11）

## 范围与口径
- 新结账的“其他收款”是非实际收款结算：保留选用草稿，不生成收款事实，不计现金业绩；权益后续实际核销继续按既有消耗、劳动业绩规则处理。
- 充值及销售/充值欠款补交不允许选择“其他收款”。新销售订单展示该非现金结算，整单作废按原资格校验反向冲销；历史已有真实收款事实不重写。
- 产品经理进一步明确：凡原销售订单使用过“其他收款”，包括历史单与混合结算单，卡项及项目/产品明细均不得退款，只能在符合原有条件时整单作废。候选可见禁退原因；提交事务重新核对。无原单目录退款不属于订单退款，仍按原权限与规则处理。
- 未更改已完成订单的收款/业绩事实；未执行部署、远程写库、真实资金操作、commit 或 push。

## 自测
- 结算合同 93 断言通过；旧卡/其他收款事实等式、订单详情一致性、整单作废、充值/欠款方法边界合同通过。
- 本地 Docker `ruihao` MySQL 只读+事务回滚探针：历史其他收款项目明细、卡项候选均禁退；绕过页面直提退款命令被服务端拒绝。未落退款事实。
- 退款行分配及抵用券边界合同通过；PHP 语法检查、`git diff --check` 通过。
- `cashier-v3 npm run build` 成功，只有既有大 chunk 提示；生成 `dist/` 非源码、未列入提交。
- 未在本轮产生一笔真实新口径结账或真实核销；需产品经理在固定版本页面完成业务验收。

## 固定版本
- 起始检查时 HEAD：`fc30a0b7108b7b3898664e5a3aa8b62b4e76acf3`。期间另一任务提交了无关证据；固定时 HEAD：`2ce93d0fb8a80c5262ce75afa31b35f93762d62c`，未回退或纳入该提交。
- 下表 27 个闭环文件加架构文档的 SHA-256 汇总指纹：`c408f78edb99f298e4a087d9dd33672371feccff89d89809ff93cca5a115f297`。
- 当前 HEAD 上这些已跟踪文件的二进制 Git 补丁 SHA-256：`ad787bebcef193e112bd0860cda204f3d1850fab09cd39e58f51e85a203fef18`；两个新增测试文件以文件哈希记录。
- `CashierV3SalesOrderQueryServices.php` 和 `MetricDictionaryServices.php` 在本轮开始前已有其他未提交改动；以下是当前完整文件哈希，提交时必须逐 hunks 核对，不得夹带别的闭环。
- 业务注释已在结算内核、收款计划、订单查询/冲销/退款、事实组装、充值/欠款边界及前端相关处理处补充或同步。
- 源码分组：前端 7、后端 13、数据库迁移 0、自动化测试 7、工程脚本 0；生成物 `dist/` 不提交，验收证据为本文件。架构文档位于 Git 正本外的 `XJPMD/`，单独记录。

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php` | `cd6daf5c47f3896ba17558cb1731e0f773f535f49d82052b4f08add925a227a7` |
| `后端代码/app/services/cashier/v3/member/CashierV3RechargeCheckoutModule.php` | `2b9184d635504481bfb92e118e36229f227943230970c92a6010574947102e79` |
| `后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php` | `c18a3c36732be7b4725c365ce9d1e0f6909e781f15bb4076f5c7b752b098082d` |
| `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php` | `51fa9a5bee4e1dcdb5aaea9b5e8732464cdaec573f6345014b7f26da9c2029c2` |
| `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php` | `087ebddb7844dc146fa82185a75760661ac3822ed77c58ffc004631067d6f086` |
| `后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php` | `89e7af7b4af67d6ff898fdad0e410f7a9fff415cef29b51ecf73e79236cbdf69` |
| `后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php` | `2807e87d9a795f8f07ce3ac39595e3fb3e133e4ba389b678729c5b025bd018f2` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php` | `1f44fd9391cb562d997b1b7ee0570285d4e0c648b946b2d84a07e7d5e1e8b047` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php` | `e7bf5fbdd92dd5d7e29a1834e28d61c6267b2747e95455af83581b76de3de5de` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php` | `eac7e8682df2f98c4804cba00c4e882561da786921cf7c3cea5975328ecdad3d` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3RechargeDebtRepaymentServices.php` | `49383eb6ff89ec81a6e491fc67e6155e23d107fd28eb3c4092165bb9df2c0982` |
| `后端代码/app/services/cashier/v3/settlement/payment/CashierV3PaymentCollectionPlanV1.php` | `0f79227a19cb6c886db8d511e3d2dcc81023e3de3b80ca5150818e3712cdde5f` |
| `后端代码/app/services/metric/MetricDictionaryServices.php` | `e56ca10e7fb1d159b820024971fdaea3a2018cbd2c42eb6bc236ff644dc058a7` |
| `前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue` | `a83546b54290219aa575981f1f07f6eebe1b669d735e77e3583277e09d849d87` |
| `前端代码/cashier-v3/src/components/member/RechargeOverlay.vue` | `b08d97882e5dc4aaf6984aad9f6797f5911f55ff431c576110144a05dc2346d1` |
| `前端代码/cashier-v3/src/composables/useRechargeCheckout.js` | `2181692a430b1065dd8acae421d96129b174f07bd741aa2441b5731eb9af8943` |
| `前端代码/cashier-v3/src/dev/pdV3FixtureMain.js` | `05cfb4174f2e4a9b35b3f2975e139a4ae336dbf620d7ae7423db8c2ee712984c` |
| `前端代码/cashier-v3/src/services/cashierV3Bridge.js` | `dc56409a6e52fd1a7a7b341b312936394b77b5ab984b575d63fa98f8be68d992` |
| `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `129493df478e59bb40f37b9203ef93d261a539a55d82f7c401e33f313b18db9e` |
| `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `a0e17923c047edaf1882825bca6a8540d45fac0ffbdc92245b1712df621a2021` |
| `tests/cashier-v3/php/c2-checkout-settlement-contract.php` | `04a499bfd0cfbb64b97691da38fc5b1c8617178a3507abefb9a97f1c146bae38` |
| `tests/cashier-v3/php/old-card-accounting-config-contract.php` | `223e15c089da1adb7a31eb3668b9802894eceb32e8aaee24290792dc8234a9f3` |
| `tests/cashier-v3/php/old-card-entry-fact-equation-contract.php` | `90bc4eb59535c75749b56b584c5613d51f1c3c2044cc0f0fd5db6b8bddc5e00f` |
| `tests/cashier-v3/php/old-card-void-contract.php` | `b09588b5c0855e3db06dbb4af1b10042cca18a6d064744fc4db0748053582c6c` |
| `tests/cashier-v3/php/order-center-settlement-integrity-contract.php` | `b2badcd902a4866e4534b93af5bb6177044634e867e0c452012bf883de09b487` |
| `tests/cashier-v3/php/other-collection-noncash-boundary-contract.php` | `4fa0c5ec9d00e7a54ae026cd0a07a4dac249ec871082705eab20b0b324c150c7` |
| `tests/cashier-v3/php/other-collection-refund-local-mysql.php` | `fdea6388495d36884e3b6c3f2c399bca139a0bad998ce627f53d05f07c90da7b` |
| `../XJPMD/14-统一数据底层架构.md` | `7d25c150b92dd577217f9fa8f6a51ccacc3b68fdfd8eb0e21b8994d31ac83d3e` |

## 状态
开发与本地自测完成，待产品经理对上述固定版本确认验收。验收前不创建本地 commit；部署瑞昊与 push 需要另行明确授权。
