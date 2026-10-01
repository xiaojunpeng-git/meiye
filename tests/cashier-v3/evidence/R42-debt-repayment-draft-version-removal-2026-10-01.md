# R42 补交旧草稿／版本路径删除证据

- 本地基线 HEAD：`dcbe7fe3ba9b3c53de777e3a0192ceac453bf1ca`。本文件记录的是尚未提交的工作区版本；不代表瑞昊线上已更新。
- 范围：销售欠款补交与充值欠款补交。普通收银、盘点和其他业务的草稿／资源并发版本不在本次删除范围。
- 新路径：会员欠款页“下一步”只在浏览器交接本次补交资料；最终“确认收款”提交唯一 `checkoutSnapshot`。服务端事务锁定当前欠款、来源、余额与收款事实；同一幂等键仅允许相同快照重放。
- 删除：两种 `prepare-*-debt-repayment` 前后端动作与权限入口，销售补交 `prepareInTx`、补交草稿读写／恢复、旧结账请求版本提交分支，充值补交 `prepareCheckoutInTx` 及旧结账请求提交分支，闲置的补交草稿 ID 生成器及开发夹具旧动作。
- 保留：历史成功补交事实的只读展示／查询、数据库历史行、迁移文件、真正需要的业务事实版本与最终事务并发锁。没有执行删除数据库记录或远程数据操作。

## 源码引用扫描

在 `后端代码/app` 与 `前端代码/cashier-v3/src` 执行：

```sh
rg -n 'prepare-debt-repayment|prepare-recharge-debt-repayment|cashier_v3_debt_repayment_draft|draftId\(' 后端代码/app 前端代码/cashier-v3/src
```

结果：无匹配。补交服务内亦无 `persistDraftInTx`、`lockReusableEditingDraft`、`submitRechargeDebtThroughCheckout` 或 `lockAggregateForEditInTx`。服务端仅在最终提交边界拒绝携带旧 `checkoutRequestId`／`checkoutRequestVersion` 的请求；它们不再参与补交结算。

## 自测

- PHP 语法：销售补交服务、充值补交服务、模块注册、动作清单、上下文策略均通过 `php -l`。
- `SALES_DEBT_REPAYMENT_CONTRACT_OK`
- `RECHARGE_DEBT_REPAYMENT_CONTRACT_OK`
- `PASS debt repayment identifiers are stable and scope-bound`
- `DEBT_REPAYMENT_UNIQUE_SNAPSHOT_CONTRACT_OK`
- `RECHARGE_DEBT_REPAYMENT_FRONTEND_CONTRACT_OK`
- `PASS cross-store debt repayment contract`
- 门店端 `npm run build` 成功（1983 模块；仅已有大包警告）。
- `git diff --check` 无错误。

另运行较大范围旧测试 `checkout-snapshot-direct-authority-contract.php`：42 通过、4 失败；失败项是销售人员／权益选择器等非本次补交断言，未宣称该套测试全绿。未执行真实线上资金补交，产品经理尚未对这一固定版本确认验收，因此未 commit、未部署、未 push。

## 注释

本次在 `CashierV3DebtRepaymentServices.php`、`CashierV3CashierModule.php`、`CashierV3ContextPolicyRegistry.php` 补充／更新了唯一快照、事务锁与旧请求拒绝边界说明；自动化测试中的相应契约注释同步更新。
