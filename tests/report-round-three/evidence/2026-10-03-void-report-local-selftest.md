# 第 3 轮：作废数据与 Vue3 报表本地自测

状态：本地开发与自测进行到事实层、正常报表读路径和补交作废写路径；**不等于全量事实迁移完成或产品经理验收通过**。未 commit、未部署、未 push、未连接或修改瑞昊线上数据库。

## 固定范围

- 基线 HEAD：`8336b6e6f9b005a64f9837a56e753cf5e7ae500c`。
- 已跟踪源码补丁 SHA-256：`a0ea48522e7eacd48f3a10eebe23d506908b1277023e2220075a59af990d8539`；新增文件以逐文件 SHA-256 标识。
- 前端源码：无。Vue2 和正常收银页面均未修改。数据库迁移：无。工程脚本：无。生成物：无。
- 后端源码：`CashierV3OrderCenterVoidServices`、`MobileWarehouseServices`、`RegisteredMetricReadServices`、`CustomerAnalyticsServices`、`CustomerLifecycleNormalReadServices`、`GroupManagementDashboardDailyAggregateServices`、`MemberManagementDashboardServices`、`ProductManagementDashboardServices`、`StoreReportNormalDataScopeServices`、`StoreUnifiedReportServices` 及 PhaseTwo/Three/Four/Six。上述业务代码均补充或更新了作废事实、历史投影、当前权益例外的边界注释。
- 自动化测试：本目录 `php/` 下 4 个脚本；只读历史审计：`sql/void-history-audit.sql`。

## 验证

- SQLite 6 个正常口径样例通过：成功作废原事实及反向事实均不进统计；退款、失败作废、跨租户/门店和无关服务仍保留。
- 本地 MySQL `127.0.0.1:3307/ruihao` 只读执行：5 类事实查询、3 项注册指标、4 个基础报表、Vue3 目录 70 张报表及会员/产品/集团 3 个看板，**73/73**。本地 7 条旧首次疗程投影的作废订单均未进入新的正常生命周期读取。
- 本地事务回滚实测：销售补交 `BJ26100300005`、充值补交 `BJ26100300004` 各执行一次真实 V3 作废命令；收款和业绩冲销净额对平，销售分摊净额对平，充值余额事实冲销；正常报表中对应收款从原金额变为 0。事务回滚后补交状态仍为 `succeeded`，无测试作废操作留存。
- 只读审计 SQL 在本地库的 7 类“成功作废但缺负向事实”检查均为 0 行；另发现 3 个“已作废首次疗程后还有有效卡销售”的历史个案，**未自动改写**。
- PHP 语法、`git diff --check`、本轮 SQLite/入口合同和既有 Phase 3/6/7、移动数仓 PHP 合同通过；门店报表、Phase 6、集团看板 JS 合同通过。
- 已确认的基线既有失败：Phase 3 旧权限字面量合同、产品看板旧方法名静态合同、收银旧客次 SQL 字面量合同、移动数仓缺失 UTS 文件的 JS 合同；本轮未改这些无关文件或用它们冒充 PASS。

## 未解决边界

1. Vue3 “客户未耗分析”和 PhaseTwo 的当前卡项剩余量仍读取 `user_card_holder` / `store_order_cart_info`；本轮已加成功销售作废隔离，但**没有**能代替它们的逐权益余额事实快照。PhaseFour 的当前未结欠款仍需 `store_debt` / `store_debt_item` 作为余额权威；分类、员工、手机号等业务表仅作维度补充。这些报表不能宣称“全部已改为只读事实表”。需要独立设计权益/欠款余额快照及迁移与对账，不能直接删或猜算。
2. 旧生命周期投影中有 7 个首次疗程订单已作废，当前读路径不再展示作废来源；其中 3 个会员后来又买了有效卡项，但原投影阻止了新的首次疗程事件生成。是否已结清、是否升级卡及嘉宾身份需要逐单核实后重建，不能从一条销售事实自动推断；市场首次疗程渠道统计可能因此漏掉有效后续卡单。只读审计 SQL 已列出候选。
3. `MobileMerchantDashboardServices` 等非“数据”报表入口仍需另行对照旧交易与 V3 事实覆盖期；本轮没有对 Vue2 或移动商家首页做迁移。
4. 尚无瑞昊线上只读审计、真实页面逐报表对账、产品经理固定版本验收；不具备提交或部署条件。

## 文件 SHA-256

```text
5e05ad1f055962bf40f0d66830e0ba62a4db274eb987b5bdb34b91a06612605e  后端代码/app/services/cashier/v3/order/CashierV3OrderCenterVoidServices.php
40f1ca781d6d7948a34f1aeaa6b7c5e8a13b8ae1dbfcc57fa4b8f490b1853d63  后端代码/app/services/mobile/warehouse/MobileWarehouseServices.php
6e6fe04738cefd29872545b1ec98b8fb624d0e3f7650323a57b87c0113769c69  后端代码/app/services/query/metric/RegisteredMetricReadServices.php
aa894809324d875ee198db54db9ef646fd61176641f487e6fc2858cab3951ecc  后端代码/app/services/report/CustomerAnalyticsServices.php
87978fb2cce3cde73051d9a391a412a9caf14fdbce2c2dbb9d6cdf25da7a55ed  后端代码/app/services/report/CustomerLifecycleNormalReadServices.php
68a9efa424e7164bcf39ed224f09c48b76be4d056705fd5bbe79897e13d8ecc1  后端代码/app/services/report/GroupManagementDashboardDailyAggregateServices.php
b512d31474bfc865f90d225de7ea6a40e479e3da0fcdbf08e4cc01b133bd2e23  后端代码/app/services/report/MemberManagementDashboardServices.php
662be206876df16b9e59fe05ed703f7e3dce8772e2fd282e0f87731806b27ff2  后端代码/app/services/report/ProductManagementDashboardServices.php
252f05741734c0d2d2155bafd504a8c0230ae2c682559aa3f4b75dc35eeaa7fe  后端代码/app/services/report/StoreReportNormalDataScopeServices.php
2622f5cde4b2dd302ca2f4d3d22b182f584a9ef6ceed57ca9b3556138c0ccd57  后端代码/app/services/report/StoreUnifiedReportServices.php
9977215f17f55fceb18694d24479edcaa0584cf7f330f5472dbad173fbb573c8  后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php
df8899c325a401ec8fa9266f95de7acf4e51562c49e5aeafe0be21414c33f386  后端代码/app/services/report/StoreUnifiedReportPhaseThreeServices.php
9aaa454fa307cfc3c041d099f234d99e6c170c66977cec6e57af0ccc639d4e3f  后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php
b8143dec40cf27151b02a861040173070a02216da16d3dfe395be9eee5af772e  后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php
363f399c4a35e09168f2418b8cef8695f68353ba54ba57de04562e6226a073f4  tests/report-round-three/php/normal-void-scope-local-mysql.php
8d0c91fc30b460956597dc37dbb0c71b8f6d826cea18622ce5046afa54c25aa3  tests/report-round-three/php/normal-void-rollback-local-mysql.php
65cbd7ce7fa43207cbfb347169cbb7fb890cc3a032c876ad046e58f2e8a9fab0  tests/report-round-three/php/normal-void-scope-sqlite.php
3da89229b4de04ba40be660a1ba5f2bfe82d3d797c21c6e3bc75233f6462428e  tests/report-round-three/php/vue3-void-entry-contract.php
4b61ae9e0e3e0f54364f1cfde2860ae2b67793aded5de358a2d489f3adc24cf9  tests/report-round-three/sql/void-history-audit.sql
```
