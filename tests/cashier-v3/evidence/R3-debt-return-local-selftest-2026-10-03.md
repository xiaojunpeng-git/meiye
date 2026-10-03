# 第3轮补交返回欠款明细本地修复记录

日期：2026-10-03。基线 HEAD `544d013d0457c971813ffbed032290b84c17225f`。范围仅为 Vue3 收银结账浮层的“返回欠款明细”动作；订单中心“去还款”经产品经理实测正常，本次没有调整该路径。

## 原因与改动

补交成功后，返回按钮原先只发出普通关闭事件；工作台已将成功补交标记为需要刷新根状态，普通关闭因此整页刷新，欠款浮层没有重新打开。现在返回事件明确标记欠款目的地：先从本次快照取会员 ID，释放结账快照，成功时刷新收银根状态，再通过已有欠款入口重新读取该会员的服务端最新欠款。根状态刷新发生异常时，独立的欠款读取仍会继续。普通结账关闭分支未改变。

`preparationRequestId` 用于服务端准备型结账，将准备请求与其快照绑定，防止误用其他准备结果。当前本地补交快照流程不靠它完成返回；之前把订单中心“去还款”的一次异常归因于缺少该字段，证据不足，此处更正。

## 验证

- `checkout-return-to-debt-contract.mjs` 通过：执行成功补交、未提交补交、根状态刷新异常及普通结账四种返回分支。
- `checkout-return-button-placement-contract.mjs` 通过；`checkout-failed-return-contract.mjs` 6/6 通过。
- `npm run build` 通过，1988 模块完成编译；仅有原有的大包体积提示。
- 相关文件 `git diff --check` 通过。
- 尚未在有登录态的 18091 标签执行真实补交后的点击验收。现有已登录标签属于产品经理正在使用的会员现场，未操作；独立测试标签没有登录态。不能将编译和执行测试等同于真实页面验收。

## 固定文件清单

前端源码：`前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue`、`前端代码/cashier-v3/src/views/CashierWorkbenchView.vue`。

自动化测试：`tests/cashier-v3/js/checkout-return-to-debt-contract.mjs`。

后端源码、数据库迁移、工程脚本：无。构建产物 `前端代码/cashier-v3/dist/` 为派生文件，不作为源码或提交范围。本文件为验收证据。修改的业务代码已注释成功补交与根状态刷新、权威欠款重读的边界。

```text
c81ec76f8cad3b633791df85274a6649942d38563438e8250f10010907f7d3db  CashierCheckoutOverlay.vue
20b9a4d2717a550bc4f610312d37db085e9b37075374f9b34ff8d639e8568c40  CashierWorkbenchView.vue
eb231d54ea6506663dbb291d2f704f3c0a9588345a6588c90123dd00782a823f  checkout-return-to-debt-contract.mjs
```

状态：开发与本地自测完成，待产品经理在 18091 固定版本确认验收。未 commit、部署或 push。
