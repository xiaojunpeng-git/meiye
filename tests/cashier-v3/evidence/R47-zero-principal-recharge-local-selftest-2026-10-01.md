# R47 本地零本金赠金充值自测（2026-10-01）

## 固定版本与范围

- 基线 HEAD：`a242ea3c130b5fe89f5ebf4c80874bc24b0e95df`
- 本闭环补丁 SHA-256：`396f4764eb528c5d8a3fdfd1ffb5cde40a28ff609fa45063916d6062e36218af`
- 前端源码：`前端代码/cashier-v3/src/components/member/RechargeOverlay.vue` (`ee74366c…`)、`前端代码/cashier-v3/src/composables/useRechargeCheckout.js` (`2e0b9add…`)
- 后端源码：`后端代码/app/services/cashier/v3/member/CashierV3RechargeCheckoutModule.php` (`8acd1f26…`)、`后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php` (`028f5d64…`)、`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` (`f0fc19dd…`)
- 自动化测试：`tests/cashier-v3/js/recharge-overlay-frontend-contract.mjs` (`d99a6a7b…`)、`tests/cashier-v3/php/recharge-checkout-contract.php` (`e16f1c79…`)
- 数据库迁移、工程脚本、生成物：无。本文件为验收证据，不属于业务源码。

## 口径与代码注释

自定义充值允许本金 0，但赠金必须大于 0、欠款必须为 0、备注必填；正本金充值的备注可选。最终确认快照携带备注，充值主单保存备注并以 `bonus_only` 标记无收款纯赠金；不生成虚假收款或现金业绩。充值弹窗、快照、最终事务、订单中心展示均已覆盖。

本次在充值弹窗、快照、最终充值服务、订单中心查询和合同测试中补充／更新了业务边界注释。余额写入适配器使用严格字段合同，备注只保存到充值主单，不传入余额写入命令。

## 自测与真实本地记录

- PHP 语法：上述 3 个后端文件均通过 `php -l`。
- 前端合同：`node tests/cashier-v3/js/recharge-overlay-frontend-contract.mjs`，38 项通过。
- 后端合同：`php tests/cashier-v3/php/recharge-checkout-contract.php`，9 项通过。
- 前端构建：`npm run build` 通过（只有既有大 chunk 警告）。
- 相关文件 `git diff --check` 通过。
- 本地 `ruihao` 库：门店 16（江苏淮盛店）、会员 UID 1130554（0091015196）、来源 ID 17（A老客首护），按门店操作员 1147 的权限执行一次 `submit-recharge-checkout` 最终快照。
- 成功单号 `CZ26100100001`，充值主单 ID 195；`price=0.00`、`give_price=300.00`、`recharge_type=bonus_only`、来源 `A老客首护`、备注“第47轮本地测试：本金0元、赠金300元”。
- 会员余额：本金 `0.00`，赠金 `0.00 → 300.00`，总余额 `0.00 → 300.00`。余额事实本金增量 0 分、赠金增量 30000 分；本单支付事实 0 条、现金业绩事实 0 条。
- 首次真实提交在严格余额写入合同处被拒绝并整笔回滚，余额与主单数量均未变化；移除错误传入的备注字段并增加合同检查后，使用新幂等键成功提交。未发生重复充值。
- 本次真实写单通过本地应用的同一 `CashierV3ActionDispatcher` 最终命令执行，不是浏览器里点击“确认充值”。浏览器测试会话的会员选择未稳定回填，因此前端完整点击路径尚未证实；页面代码已通过构建与合同测试，仍待产品经理从页面验收。

## 已知边界及发布状态

本次只修复零本金赠金充值。现有 `CashierV3RechargeModule::factPlan()` 对所有充值余额事实的来源字段仍固定写 0／空；本单主单来源正确，但余额事实中的来源为空。这是既有统一事实来源归属缺口，未在本闭环擅自扩大为历史事实重写，应另行评估来源报表是否受影响。

代码与本地最终命令自测完成，页面完整点击路径待验收，且待产品经理对上述固定版本明确确认验收；未 commit、未部署、未 push，也未操作瑞昊线上。
