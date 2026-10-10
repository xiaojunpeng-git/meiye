# R41 收银本轮优化固定验收版本

- 基线 HEAD：`217200a11472c7942d5c2813c12a01c31a25de31`。
- 产品经理于 2026-10-11 在本轮购物车、使用权益、挂单列表及库存提示汇总后明确表示“我测试没有问题,本轮commit,部署瑞昊,push”。此确认仅覆盖下列固定文件与已描述范围，不包括同工作区的会员选择、后台、手机端或其他业务修改。
- 本轮分为购物车与使用权益展示、挂单手机号/来源历史快照、库存失败提示三个独立提交。权威结账金额、库存扣减、收款事实、幂等键和回滚规则未改；挂单手机号只取挂单时服务端快照，旧单不回填。
- 自动化复测：门店端 `npm run build` 通过；购物车/权益相关 9 条 Node 契约测试通过，其中权益结账快照测试 62 项；权益单价 PHP 测试 12 项通过；挂单手机号与既有挂单回归 8 条 PHP 测试通过；库存错误转换 11 个原因码通过。PHP 7.4 目标语法仍需在瑞昊候选文件上检查。
- 已知限制：旧 `guest-card-and-inventory-guard-contract.mjs` 在与本轮无关的 `isMemberRequiredOpen` 断言处失败，未执行到新库存文案断言；旧 `room-hang-intent-contract.php` 因历史夹具问题未通过。本轮单独的 PHP/Node 测试已覆盖变更边界。未代产品经理执行真实生产结账。
- 生成物：`前端代码/cashier-v3/dist/` 是本地测试产物，不作为发布正本；瑞昊正式和 8080 预览静态包必须从下列固定源码版本单独构建。

## 前端源码 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/components/cashier/CartLineRightControls.vue` | `a115863ae12bebcd607b0007109fc56627ff52a1261a4a7a334a2e8b807a4364` |
| `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `9ff4b81041e7d18a980004b8b5d40b704d3ca6e503c68f277dbacccd86d209d0` |
| `前端代码/cashier-v3/src/styles/base.css` | `1bbbe29ea51527ff28b19bde0961d11c146d8ba6bf824b67ae827d8957960452` |
| `前端代码/cashier-v3/src/views/HangOrderListView.vue` | `80c6403c8851205684b9fe94f17590d5760bdfc82ab992845f0a90f5de91f01b` |

## 后端源码 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/cashier/v3/hang/CashierV3HangSubmissionServices.php` | `6783506ad38f96c33a1da9460c44ffdc76971b4dfcc4cd89bfefc7e2ce7309f3` |
| `后端代码/app/services/cashier/v3/hang/CashierV3HangOrderListServices.php` | `16ab3b6ebcdd5cbbc6fe5650422b8e830eb501d05b7e287fb65985060c0ee9ab` |
| `后端代码/app/services/cashier/v3/hang/CashierV3HangMemberPhoneSnapshot.php` | `1051a4211428536a966d604ae9b0734584e5ffa666d9be046123f60ac3579dab` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutInventoryFailureMessage.php` | `e873c6bb8a94ae535748f8aeaa938875b17a6865945a8f02ca5d1a34a0d9c8e7` |
| `后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php` | `bb5ffc54b1bf552a70a105ce131880e1cd8884bb29aea05c560cd7af6b05aec6` |
| `后端代码/app/services/cashier/v3/settlement/ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php` | `1e92d64de321d8f7d3d468e76e52318be815d0cb2ae0aa52392f8818f1922ac5` |

## 自动化测试 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `tests/cashier-v3/js/cart-line-detail-remark-contract.mjs` | `d7b88241cf85e40d84014e37a84883a2c13b038c92241383dc5e0c39d1ef62a8` |
| `tests/cashier-v3/js/cart-line-quantity-total-contract.mjs` | `6cc9b931aac842cf0e8db70ceaed0550e30320f3b100dd579b0ea50bc04063ec` |
| `tests/cashier-v3/js/cart-line-metadata-layout-contract.mjs` | `6f45904cf1feef821e813803928792563be74a2762631ccee6635112d1ba652a` |
| `tests/cashier-v3/js/cart-line-personnel-summary-contract.mjs` | `16faff440267519d94dece30cf306ff80b10f2ed6bc667243fda56e20cfc8f57` |
| `tests/cashier-v3/js/cart-line-guide-round-layout-contract.mjs` | `49eaaa78d1474438128eb3f9fa6ef46a833133fe3f718e15923a61337075eaa7` |
| `tests/cashier-v3/js/cart-line-approved-font-size-contract.mjs` | `215eef2d4af752b13fa13247db80eb33318025a2efe653f50f7583353bf0bda8` |
| `tests/cashier-v3/js/personnel-performance-overlay-contract.mjs` | `3bae904af0d164495d4fa5c422bf385b62de8b1dbdd6867aa2541945676de439` |
| `tests/cashier-v3/js/guest-card-and-inventory-guard-contract.mjs` | `346ca11b3ff6408de864c2ecbc89cbf24cf6eb558cde428bdc00261c9bf01e46` |
| `tests/cashier-v3/php/entitlement-unit-price-contract.php` | `3c5d2917ed5f6f2573898b9d37fd6d2210962a236018497d7c145d1563e9e523` |
| `tests/cashier-v3/php/hang-list-member-phone-snapshot-contract.php` | `b1506f713fe5d4fcb76df233e9ecfd0add9deea571fa2719443365a306b783d5` |
| `tests/cashier-v3/php/checkout-inventory-failure-message-contract.php` | `99abbefeb65ef5ef6eb667de71801c303c5964ea822101a9382633255bbf57e3` |

相关自测记录：`R41-cart-service-controls-selftest-2026-10-11.md`、`R41-cart-metadata-layout-selftest-2026-10-11.md`、`R41-guide-round-half-column-layout-selftest-2026-10-11.md`、`R41-hang-contact-source-list-selftest-2026-10-11.md`、`cart-line-approved-font-size-selftest-2026-10-11.md`、`cart-line-remark-value-color-selftest-2026-10-11.md`、`cart-meta-value-color-selftest-2026-10-11.md`、`checkout-inventory-message-selftest-2026-10-11.md`。原自测文件中的“待验收”记录保留为当时事实，本文件登记后续产品经理确认。
