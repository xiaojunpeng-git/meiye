# R42 结账后预约提示误报修复：本地自测

## 范围与权威边界

- 基线 HEAD：`a3633f0c9db3441d5c53128e13e857972655f183`；本轮只修改门店端 `CashierWorkbenchView.vue` 的结账后预约检查分支及对应前端合同测试。
- 当前文件 SHA-256：`CashierWorkbenchView.vue` 为 `d3a2bbb77972240d5693227d9e25636ad9552ab38e4c11ea43238459cfeaa0c9`；`checkout-reservation-completion-contract.mjs` 为 `ce6ebd1126de65acdc9936e7e3abc227a41647ec86dd3d131e183c87f2631ce9`。
- 权威源：已成功结账的销售订单／持久化结账请求，以及服务端预约表。浏览器仅提交成功回执里的标识；预约数量与状态由后端按当前门店、会员和业务日查询。
- 最小粒度与生效时点：本修复不新增业务事实，不改销售、收款或预约状态。原结账事务已经成功后才执行只读检查；只有操作员选择结束预约，才进入既有独立写命令。
- 事务、并发、幂等、冲销：结账幂等及预约完成命令的事务、版本 CAS 和审计均未修改。查询失败不重试结账、不生成预约完成事实。
- 历史快照、权限、性能：继续使用服务端门店和会员范围及已结算订单业务日；没有新增跨表统计或报表口径。无数据库迁移。

## 修复与验证

1. 直接结账的临时快照标识不再冒充持久化 `CKR-...` 请求；没有有效销售订单或持久化请求时不发预约查询。
2. 查询非成功或网络异常只在开发日志记录，不向收银员弹出“结账已成功，但未能检查当日预约”。只有后端返回 `required=true` 且未结束预约数大于 0 才展示预约操作提示。
3. 本地日志 `20261001_error.log` 的 `15:23:59` 与 `15:24:52` 两次查询均为 `INVALID_COMMAND_CONTEXT／结账请求标识无效`，解释了原截图的误报；这不是结账失败证据。
4. `node tests/cashier-v3/js/checkout-reservation-completion-contract.mjs`：通过；`php tests/cashier-v3/php/checkout-reservation-completion-contract.php`：通过；门店端 `npm run build`：通过；`git diff --check`：通过。

## 交付边界

- 前端源码：`CashierWorkbenchView.vue`；自动化测试：`checkout-reservation-completion-contract.mjs`；后端源码、迁移、工程脚本、生成物：无；证据：本文件。
- 未对真实预约执行完成命令，未部署线上。仓库同一前端文件还有此前未归档的其他改动，提交前必须逐段核对暂存边界。产品经理尚未对本固定范围确认验收，因此未 commit、未部署、未 push。
