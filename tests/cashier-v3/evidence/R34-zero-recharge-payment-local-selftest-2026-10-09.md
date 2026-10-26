# R34 充值结账 0 元收款方式本地自测

- 基线 HEAD：`c0d9f7dc2253e41e21a95b56fe94f026eb9cb6ec`
- 范围：仅充值结账页面的 0 元收款方式；普通销售和余额抵扣的正数金额限制不变。
- 前端源码：`前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue` (`d8368855a5164e46db657ef859c0d191f269b671b7839fb813df798c7b34eedd`)；`前端代码/cashier-v3/src/composables/useRechargeCheckout.js` (`8c473a96292df223dd142839ab9a7cd3ca2e30bb6b64e154290f8054e3cb3f4e`)。
- 自动化测试：`tests/cashier-v3/js/recharge-zero-payment-line-contract.mjs` (`5c97a1f6df7bf3d46f5b7439dd426b0747a7fad0f46dc66657f7b8f11b6d4d97`)。
- 后端源码、数据库迁移、工程脚本：无变更。生成物：本地 Vite `dist/`，不属于源码交付。

业务边界：0 元收款方式仅保留在充值确认的浏览器快照中，实际提交前剔除。后端仍按本次实收本金校验正数收款明细总额；0 元充值不生成收款事实。原有重复提交幂等键及备注约束不变。

本地验证：

- `node tests/cashier-v3/js/recharge-zero-payment-line-contract.mjs`：12 项通过；覆盖 0 元方法保留、0 元编辑、纯赠金提交空收款明细、正数充值过滤 0 元方法、负数拒绝。
- `node tests/cashier-v3/js/recharge-overlay-frontend-contract.mjs`：38 项通过。
- `node tests/cashier-v3/js/zero-receivable-payment-method-contract.mjs`：6 项通过。
- `php tests/cashier-v3/php/recharge-checkout-contract.php`：9 项通过。
- `npm run build`（`前端代码/cashier-v3/`）：通过，Vite 仅报告既有大包提示。
- `git diff --check`（本轮前端文件）：通过。

注释同步更新：`CashierCheckoutOverlay.vue` 说明 0 元方法只属于页面快照及其他收款边界；`useRechargeCheckout.js` 说明实际收款事实仅包含正数明细。尚未做真实充值写入测试，也未取得产品经理对本固定版本的验收确认；未 commit、部署或 push。
