# R15 复验版本：欠款卡签发与权益服务结果追查

状态：开发和本地自测完成，等待产品经理对本固定版本重新验收。未提交、未部署瑞昊、未推送。

## 边界

- 基线 HEAD：`57d5de0fea0958c4ad0bcc6bb6139976001ea6c0`。同一工作区有其他任务的修改及暂存文件；本轮未处理或暂存它们。
- 购卡最终事务锁定当前商品时，仅比较“欠款不允许核销”策略与本次购买快照；不一致则回滚并要求刷新商品。不改原价、卡内项目或既售卡历史。
- 本地结账预览的“查询原结果”只发送原 `CHECKOUT-*` 幂等键到只读接口。只有绑定的正式回执才能显示成功或失败；处理中、无凭证、连接错误继续保持结果未知，不重发收款命令。
- 权益服务超时文案显示“服务”；充值显示“充值”，其他结账显示“结账”。
- 本地卡号 `3594236` 及其两次既成核销按产品经理要求保持不动。

## 验证

- `php -l 后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php`：通过（PHP 依赖和原有可空参数有弃用警告）。
- `php -d error_reporting=0 tests/cashier-v3/php/r15-card-debt-writeoff-policy-contract.php`：通过，含新售卡双向策略不一致拒绝及旧卡不追溯。
- `php -d error_reporting=0 tests/cashier-v3/php/card-purchase-issuance-contract.php`：31 项通过。
- `node tests/cashier-v3/js/r15-checkout-original-result-contract.mjs`：通过，覆盖权益完成、处理中、失败、错键、错关联及缺凭证。
- `前端代码/cashier-v3` 下 `npm run build`：通过；仅有既有大 chunk 提示。`git diff --check`：通过。
- 旧 `local-draft-final-confirmation-contract.mjs` 仍因 `openCheckout()` 结构断言与当前源码不匹配而失败；未改该旧测试或将其列为通过。
- 本地 18091 页面可正常加载。此次未再次人为制造 502，超时后的真人点击路径待产品经理按新版复验。

## 本次补充/更新注释

`CashierV3CardPurchaseIssuanceServices.php`、`CashierCheckoutOverlay.vue`、`CashierWorkbenchView.vue`、`cashierV3CheckoutResultContract.js`、`r15-card-debt-writeoff-policy-contract.php`。原 R15 完整文件清单与购买/核销真人测试见 `R15-card-debt-writeoff-local-acceptance-2026-10-12.md` 和 `tests/product/evidence/R15-product-save-name-scope-2026-10-13.md`。

## 本次新增复验文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue` | `bebd2a9d7af77f518014e117292ece7d36cd65ee2ef364eb56cfe63ea7f47f4a` |
| `前端代码/cashier-v3/src/services/cashierV3CheckoutResultContract.js` | `6de8450e80d321c8b24fea1c506b17f475e0fd1220dbc850be4ae75305bb7231` |
| `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `2a229693d2ae6310a7b9f62adab198e45579b688ab947ae420f10a4b6865907c` |
| `后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php` | `700d7c5ecb431bd41075ffcce633b92eed8c3896c4fa065adbd608b0de52b1bf` |
| `tests/cashier-v3/php/r15-card-debt-writeoff-policy-contract.php` | `8d07b2b1aaadacd165c20c120a62ac6ef492993cd6479c32663a5930ba0141ab` |
| `tests/cashier-v3/js/r15-checkout-original-result-contract.mjs` | `e798d97e621ae209b56ceeef2241a0535c824e64cd08278f77463ef01a6b72e8` |

生成物：收银端 `dist/`，未纳入源码。数据库迁移：沿用原 R15 升级文件，本次未改迁移或历史记录。工程脚本：无。验收证据：本文和上列原 R15 记录。
