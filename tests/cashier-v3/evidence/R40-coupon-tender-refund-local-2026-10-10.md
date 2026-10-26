# R40 抵用券抵付与退款边界（本地自测，未验收）

## 口径

- 抵用券为非现金抵付，不减少成交价、购卡金额或卡项权益价值，也不进入现金业绩。
- 退款及退清实付款均不退券；只有整单作废在原事务恢复券并冲销券抵付销售事实。
- 所有新写金额为整数元；多卡签发尾差落在最后一张卡。
- 老单保留原折扣快照，历史更正尚未执行。

## 本地验证

- 本地 `ruihao` 库执行 `2026-10-10-收银V3抵用券非现金抵付/02-正式升级.sql`，未备份、未触及远程；只读验证 3 列齐备，销售行和草稿金额约束违规数均为 0。
- `c2-checkout-settlement-contract.php`：87/87，包含券抵付、券全额抵付、无券 ID 拒绝及最终权威快照重建保留券抵付。
- `card-purchase-issuance-contract.php`：31/31，含多卡整数元尾差及旧折扣字段隔离。
- `checkout-coupon-settlement-contract.php`、`order-lifecycle-refund-line-contract.php`、`r40-coupon-tender-amounts.php`、`r40-coupon-refund-boundary.php`、`order-center-sales-coupon-amount-contract.php` 均通过。
- `payment-sale-allocation-fact-contract.php`、`checkout-mixed-sale-effects-contract.php`、`zero-receivable-sale-fact-contract.php` 通过；前端 `npm run build` 通过。
- 用户反馈最终收款阶段提示“结账草稿已变化或资料不完整”后，检查到重建器遗漏草稿行已冻结的 `coupon_payment_cents`；现仅从经指纹校验的同一持久化行带回该值，不更改唯一快照、金额或券的权威来源。`checkout-coupon-settlement-contract.php`、核心结账合同、金额及退款边界测试复测通过。
- 现有 `checkout-debt-sale-contract.php`、`sales-order-service-tags-contract.php`、`local-draft-final-confirmation-contract.mjs` 仍有与本口径无关的旧文本断言失败，未将其冒充通过。

## 真人收银回归（本地门店端 18091，肥东吾悦店，2026-10-10）

- 纯权益：页面选择会员韦桂香、卡号 `6642170` 的幸福霜 1 次，确认完成服务；服务单 `FW101001705`，事实状态 `completed`，未生成销售收款。
- 销售单：页面以游客购买面部养护 1 项、现金记账 100 元，完成结账；销售单 `XS26101000203`，`sale_only`、`settled`，有效现金支付事实 10000 分。
- 混合单：页面选择会员韦桂香，购买面部养护 1 项并使用卡号 `6642170` 的幸福霜 1 次，现金记账 100 元，完成收款及服务；销售单 `XS26101000204`，`mixed`、`settled`，有效现金支付事实 10000 分；同一 `checkout_request_id` 对应服务单 `FW101001707`（购买项目）和 `FW101001708`（权益项目），均为 `completed`。
- 只读查库核对：卡号 `6642170` 剩余次数为 28；两次权益使用前为 30。以上三笔均通过实际页面操作，非接口直写或测试夹具。现金仅为本地账面测试，不代表真实收款。
- 上述三笔均未使用优惠券，不能作为用券结账真人回归证据；产品经理已反馈本轮自行测试无问题并授权提交、瑞昊部署及推送。

## 文件分组与未完成

- 前端源码：`CashierCheckoutOverlay.vue`、`CashierWorkbenchView.vue` 的券抵付显示与本地预览口径；两文件均有本轮开始前的其他改动，未清理或提交。
- 后端源码：收银 V3 结账、发卡、销售事实、销售查询、退款与整单作废相关服务；本次补充了这些文件中券额、退款和冲销边界的注释。
- 数据库迁移：本目录 `00`、`02`、`03`；旧交易未自动回填。
- 自动化测试：上述 R40 金额/退款测试及受影响合同测试；工程脚本未修改。
- 生成物：前端 `dist/` 构建结果，不是源码。验收证据：本文件。
- 待完成：用券结账、退款与整单作废的 Codex 真人业务联调；按订单核对并设计可追溯的旧单更正。当前记录写于本轮提交和瑞昊发布前，不作为已部署或已推送证明。
