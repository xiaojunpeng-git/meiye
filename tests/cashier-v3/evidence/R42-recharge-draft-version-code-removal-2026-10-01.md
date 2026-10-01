# R42 充值草稿／版本运行时代码删除证据（本地待验收）

- 基线 HEAD：`48355830265ae99dfe74ac2c33469e90df1a3ef5`。
- 范围：只处理门店端充值结账；普通收银、欠款补交、盘点等业务的草稿和并发保护不在本次删除范围。
- 页面源码：`前端代码/cashier-v3/src/composables/useRechargeCheckout.js`、`src/services/cashierV3Bridge.js`、`src/services/cashierV3ActionManifest.js`、`src/layouts/CashierShell.vue`、`src/components/cashier/CashierCheckoutOverlay.vue`、`src/views/CashierWorkbenchView.vue`。
- 后端源码：`后端代码/app/services/cashier/v3/member/CashierV3RechargeCheckoutModule.php`、`member/CashierV3RechargeCheckoutRequestVersionProvider.php`（删除）、`bootstrap/CashierV3Bootstrap.php`、`manifest/CashierV3C5MemberOrderModule.php`、`manifest/CashierV3ActionManifest.php`、`CashierV3ActionDispatcher.php`、`CashierV3ResourceKindCatalog.php`、`settlement/CashierV3CheckoutBusinessSourceSelectionServices.php`、`registry/CashierV3ContextPolicy.php`。
- 数据库迁移：无；历史充值草稿表和既有数据未删除，已执行的升级文件原样保留。
- 自动化测试：`tests/cashier-v3/js/recharge-overlay-frontend-contract.mjs`、`tests/cashier-v3/js/checkout-personnel-closure-contract.mjs`、`tests/cashier-v3/php/recharge-checkout-contract.php`、`tests/cashier-v3/php/business-source-accounting-contract.php`。
- 工程脚本：无。生成物：仅本地 Vite `dist/`，未纳入源码。验收证据：本文件。

## 删除与替代

1. 服务端充值模块从 447 行缩为 183 行：移除准备、增删改收款行、重载、编辑版本锁、草稿表读写及历史草稿提交分支；只注册 `submit-recharge-checkout`。
2. 删除充值请求版本提供器，并从前后端动作清单、后端启动注册、资源目录及调度器移除五个旧动作。
3. 前端充值流程删除旧草稿编辑／重载／版本冲突重放分支；“下一步”仅生成浏览器确认快照，最终提交复用同一幂等键，后端在事务中重新锁定余额、来源与套餐并校验收款总额。
4. 删除充值来源服务对旧编辑草稿的读取与准备写入；其他结账业务的来源服务保持不变。

## 可复核命令与结果

```sh
rg -n 'prepare-recharge-checkout|add-recharge-checkout-payment-method|update-recharge-checkout-payment-line|remove-recharge-checkout-payment-line|reload-recharge-checkout|CashierV3RechargeCheckoutRequestVersionProvider' 前端代码/cashier-v3/src 后端代码/app/services/cashier/v3
```

结果：无匹配。充值模块中亦不再有 `checkoutRequestVersion`、`lockEditing` 或草稿表操作。

- `php tests/cashier-v3/php/recharge-checkout-contract.php`：7/7 通过。
- `php tests/cashier-v3/php/business-source-accounting-contract.php`：全部通过。
- `node tests/cashier-v3/js/recharge-overlay-frontend-contract.mjs`：37/37 通过。
- `php -l 后端代码/app/services/cashier/v3/member/CashierV3RechargeCheckoutModule.php`：无语法错误。
- `npm run build`（门店端 Vue 3）：成功，1983 模块。
- `git diff --check`：通过。
- `checkout-personnel-closure-contract.mjs` 仍在无关的普通结账旧断言 `localCheckoutPreview.value = localCheckoutPreviewSnapshot()` 处失败；该断言与充值删除无关，本次未为通过它而改普通结账业务。

本次补充／更新的业务注释涉及充值组合逻辑、充值最终事务、收银桥接、调度器和上下文策略。未进行真实资金操作、线上验证、commit、部署或 push；需产品经理对本固定版本明确验收后再按项目规则提交。

## 当前源码指纹

- `useRechargeCheckout.js`：SHA-256 `6a5c6b5851814283c60ecd3a5a27f29ee2653af1c8a438e32f5a435c9f1ba59d`
- `cashierV3Bridge.js`：SHA-256 `1d07469b24fda30aa6a37382fe5853b8fed99c343d2e5b0656c2722e072d1f69`
- `CashierV3RechargeCheckoutModule.php`：SHA-256 `f863fac24238e4137ae8fb086fc86989c5b1fdeee13d0b3b9247cc97c2dafbd9`
