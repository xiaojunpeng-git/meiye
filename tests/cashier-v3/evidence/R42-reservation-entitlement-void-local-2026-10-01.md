# R42 预约来源权益单作废：本地固定版本与自测

- 基线 HEAD：`034263774c54865f50b47d1bfa1678568386c2a7`。本次尚未 commit、未部署、未 push；瑞昊线上仅作只读标识核实，未对真实订单或预约执行作废。
- 问题事实：瑞昊已完成服务 `FW100100127`、`FW100100095` 的 `checkout_request_id` 分别为 `reservation:447`、`reservation:595`，均源于同 ID 的 V3 预约。旧前端和后端只接受无冒号的结账组标识，导致页面在提交前提示“消费订单标识无效”。
- 业务边界：只接受既有普通请求 ID 与精确 `reservation:正整数` 两种组标识；展示单 ID 必须为 `service:` 加同一标识。后端仍按租户、门店、纯权益单、服务事实和幂等键复核。预约 ID 仅作为来源关联，服务作废不取消或修改预约主单、明细、权益占用、排班、结账服务关联和操作记录。
- 前端源码：`前端代码/cashier-v3/src/services/cashierV3OrderProjectionContract.js`。后端源码：`后端代码/app/services/cashier/v3/order/CashierV3EntitlementCheckoutIdentity.php`、`CashierV3OrderLifecycleModule.php`、`CashierV3SalesOrderQueryServices.php`、`CashierV3ServiceRecordVoidServices.php`。数据库迁移：无。自动化测试：`tests/cashier-v3/js/order-void-receipt-recovery.mjs`、`tests/cashier-v3/php/pure-entitlement-void-policy.php`、`tests/cashier-v3/php/r38-service-void-rule-mysql.php`。工程脚本：无。生成物：`前端代码/cashier-v3/dist/`，仅本地构建，不是开发源码或本次提交文件。本文件为验收证据。
- 自测：前端命令身份与幂等回执测试 PASS；PHP 7.4 策略测试 PASS，正常、预约、冲突和非法标识均覆盖；`r37-entitlement-sales-query-mysql.php` 只读列表、详情、权限及分页对账 PASS；`r38-service-void-rule-mysql.php` 本地事务回滚测试 PASS，预约来源单条权益的次数从 9 恢复到 10、反向权益事实数量 1、业绩正反净额 0，预约六类业务表在事务内逐项比较保持不变，测试夹具最终回滚；PHP 语法检查 PASS；门店端 `npm run build` PASS（1983 模块）；`git diff --check` PASS。未以真实线上写操作代替本地自测。
- 本次补充或更新业务注释的文件：`CashierV3EntitlementCheckoutIdentity.php`、`CashierV3SalesOrderQueryServices.php`、`CashierV3ServiceRecordVoidServices.php`、`cashierV3OrderProjectionContract.js`、`r38-service-void-rule-mysql.php`、`order-void-receipt-recovery.mjs`、`pure-entitlement-void-policy.php`。本闭环不新增或删除平行业务入口。
- 固定源码与测试清单按上述 8 个文件顺序计算的 SHA-256 清单指纹：`78f0671d94c0eb56690efd678bf36ef2ca1f94e892b1b43668dfe8ad115bbf55`。本地构建 `dist/index.html` SHA-256 `00dfffcb0e22f53a4fe52007a1374c7bf633cba9d6a783a33629852a2d7f9ce2`，主 JS `index-762d019a.js` SHA-256 `dba965d6b379f233ebd8e6fc591b347397ca68e2180dbde146251e36495eae4e`。
- 已完成开发与自测，待产品经理对本固定版本确认验收；本次“修复”未授权直接 commit、部署瑞昊或 push。
