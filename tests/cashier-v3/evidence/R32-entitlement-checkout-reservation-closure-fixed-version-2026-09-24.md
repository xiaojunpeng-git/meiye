# R32 纯权益结账同步当天预约修复固定版本

## 修复范围

- 纯权益结账成功后，使用服务端成功回执中的 `checkoutRequestId` 检查同会员、同门店、同业务日的未结束预约。
- 收银员选择“是”后，将本次未作废的完成服务事实关联到当天全部未结束预约，并统一改为已结束。
- 预约详情、列表和日历从结账服务事实的 snake_case 人员快照读取实际手艺人；原计划项目和计划手艺人继续保留，不被覆盖。
- 普通/混合结账继续以已结算正向销售订单为权威入口；预约后续动作失败不得回滚、重试或改判已成功的结账。

## 固定版本

- 基线 HEAD：`49e7fffd26f07675376b796ea816f607bf6cccfe`
- 本闭环补丁指纹（SHA-256）：`f7f218b7048299933ca03502ee689c0f52b990d33c1dd6dd59d2d4bfd9d6b5c6`
- 状态：开发与 Codex 自测完成，产品经理已确认验收，待本地提交归档。
- 本闭环尚未 commit、部署或 push。

## 文件 SHA-256

- `8d49fb66c17dcdbfa9171beedf689b3754b386d2c0871094cc2e3f1dd1d0b29f` `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue`
- `483b894aac2d8f4edf6f57c2ff23873c4ba4b6cfe151403a7403cb1452a63391` `后端代码/app/services/cashier/v3/reservation/CashierV3CheckoutReservationClosureServices.php`
- `0b3e1557d842a66f30de69d2cc0f355e6f8ce1ecd0b531c0bba0fd4fff9d8c33` `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php`
- `946b5227802cc9940d164051014db96149d37cab602442088abed0d08786999f` `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationDetailQueryServices.php`
- `ff3e2451ff8808778cbc92117dd7c5caf074b042dcc45f16f7589ffd2b22082c` `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationPartitionProvider.php`
- `9f9ce3ed3e5c1c373ab9a77ac8d454928108ee5f65bf266146ce0ad13c31c287` `tests/cashier-v3/js/checkout-reservation-completion-contract.mjs`
- `d88908cdcfc41dccd5159607b3271f40a4bf5d147c02a1c084ff888b628215c4` `tests/cashier-v3/php/checkout-reservation-completion-contract.php`
- `2f9e408a6469fd12c5efa919c2704e73447afd395cc1a45ccd095c4179c96a4d` `tests/cashier-v3/php/checkout-reservation-completion-mysql-integration.php`

## 自测结果

- PHP 语法检查：预约详情与列表投影服务通过。
- 前后端合同测试：通过。
- MySQL 回滚集成：通过；普通销售订单与纯权益 `checkoutRequestId` 两种权威入口均完成验证。
- 前端生产构建：通过；产物 `index-7fa1ee63.js`。
- 差异检查：本闭环文件 `git diff --check` 通过。
- 本地真实浏览器：纯权益结账后成功出现“是否结束当日预约”提示；点击“是”后，预约 `YY2609240006`、`YY2609240007` 均从未结束状态改为已结束。
- 项目同步：两条预约均显示本次结账项目“背部spa”，来源为 `checkout`。
- 手艺人同步：两条预约详情的“实际服务人员”均显示本次结账手艺人“黄文弟”；各自原计划手艺人继续在“计划手艺人”区域保留。
- 失败路径复盘：服务记录 `FW092400011` 的提示查询正确命中预约 `YY2609240008`，但页面先等待大体积工作台刷新，期间没有发出“是”的完成命令；现已把预约检查提前到根投影刷新之前，并由前端合同锁定执行顺序。

## 本地数据说明

- 本轮真实 UI 联调使用本地 `ruihao` 数据库；用户已逐次确认权益扣次与结束预约操作。
- 预约 `YY2609240006`、`YY2609240007` 已真实变更为已结束，并写入结账服务关联快照。
- `FW092400011` 对应预约 `YY2609240008` 仍保持未开始；本次排查只读核对，没有绕过“是/否”选择直接改预约。
- 未连接或修改瑞昊线上数据库。

## 注释更新

- 收银成功回执固化、纯权益权威回退、未作废服务事实边界以及 snake_case 人员快照兼容均补充了业务注释。
