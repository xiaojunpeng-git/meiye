# R25 旧卡退款本地自测与固定验收版本

日期：2026-10-05（Asia/Shanghai）。状态：开发与自测完成，待产品经理确认验收。未提交、未部署、未 push。

## 已确认并实现的业务范围

- 订单中心「退款记录」查询栏新增「旧卡退款」入口，复用岗位「订单中心→退款」权限（cashier.v3.order.refund），服务端再次校验。
- 选择会员后只展示未退款、仍有效且归属未转赠的导入卡。正常购买卡不进入此入口；候选分页每页 50 张。
- 单次选择一张卡，手工填写实际退款金额及原因。2026-10-05 最新要求：只接受大于 0 的整数元，前后端均拒绝小数；统一事实层以分保存。
- 允许跨店办理，退款业绩归实际办理门店。用退款时的卡、权益及欠款确认快照和事务行锁防止选择后数据变化。
- 退款终止剩余权益，保留原卡核销历史、权益明细及已补交金额。关联欠款仅取消未补交余额；已退款卡的欠款不能恢复。
- 生成退款记录及负向退款资金事实，进入统一退款业绩与实收业绩汇总、分组和下钻。导入卡没有可信的历史收款渠道，记录显示「手工确认退款」，不调用真实支付渠道。
- 不确定的提交结果保留请求编号及原输入，通过重试获取同一结果；同一请求不会再次产生退款事实。

## 数据与事务

权威来源为 user_card_holder、迁入 store_order、cashier_v3_card_state、卡规则状态/组件及 store_debt。退款操作、事件、资金事实、权益终止和欠款取消在现有命令网关事务中完成。退款记录使用 cashier_v3_order_lifecycle_operation，退款事实使用 cashier_v3_payment_fact，欠款取消使用既有 cancellation 审计表。无需新增数据库迁移。

仅连接本地 Docker mysql/ruihao。远程客户、线上迁移、实例备份与回滚点均不适用；本轮未连接生产环境。

## 验证结果

1. PHP 语法检查：本轮 7 个后端 PHP 文件及测试脚本通过。
2. manifest + resource kind selfCheck：返回 []。
3. 门店 Vue 3 源码 npm run build：1989 modules，成功。既有大 chunk 提示仍存在。
4. git diff --check：通过。
5. 本地 MySQL 事务回滚测试：21 项通过，最终标识 R25_IMPORTED_CARD_REFUND=PASS。
   - 跨店候选读取、退款权限拒绝、零金额/小数拒绝。
   - 选择后补交变化导致确认失败；转赠归属不一致拒绝。
   - 实际命令网关完成退款，真实权限重建、事件合同和回执链路均执行。
   - 退款 123 元生成 -12300 分事实，归办理门店。
   - holder、V3 卡状态、规则状态及组件都不可继续使用，历史次数及原始核销明细保留。
   - 欠款原额 1000 元、已补交 300 元保持，剩余 700 元取消。
   - 现金业绩不变，退款业绩增加 123 元、实收业绩减少 123 元；下钻和门店分组与汇总一致。
   - 退款记录可重新查询；已退款旧卡退出候选；相关欠款不再提供恢复。
   - 同一请求经网关重放仅有一条资金事实。
   - 最终检查卡、原订单、欠款、规则状态、命令回执及退款操作均回滚，不保留测试退款。
6. 真实本地浏览器：退款记录页可见入口，会员选择和候选读取正常；普通购买会员无可退款导入卡；选中部分已核销旧卡，输入 123.45 后页面阻止提交并显示整数提示。浏览器未对该真实本地会员提交有效退款。
7. 通用历史 unit-contract 套件未通过：包含旧 deferred action / permission mapping / selector scope 预期；不作为本轮通过证据。本轮功能由上述真实网关及 MySQL 测试覆盖。

复现命令：

```sh
docker exec -i -w /var/www/html mohe-cashier-app php < tests/cashier-v3/php/r25-imported-card-refund-mysql.php
```

页面证据：R25-old-card-refund-integer-validation-2026-10-05.jpg。

## 文件分组与注释

前端源码：CashierShell.vue、cashierV3ActionManifest.js、cashierV3Bridge.js、OrderCenterView.vue。后端源码：下表 7 个后端文件。数据库迁移：无。自动化测试：r25-imported-card-refund-mysql.php。工程脚本：无。

生成物：前端代码/cashier-v3/dist/（仅构建自测产物，未部署）；本次 JS 为 index-35321a1d.js、CSS 为 index-fecd1472.css。构建源为 18091 对应的 cashier-v3 当前工作区；这次构建不代表 8080 集成部署完成。

证据：本文件和上述 JPG。注释重点已在导入卡退款服务、生命周期注册、事实查询、欠款恢复/查询、前端输入与重试状态附近补充，说明权限、归属、整数单位、保留核销历史、原欠款取消、事务及幂等约束。

共享工作区含其他任务变更。特别是 CashierShell.vue 的专属服务人多选改动不属于 R25；固定文件指纹包含当时完整文件，仅用于验收识别，后续暂存必须按 R25 业务 hunk 边界处理，不可整仓提交。

## 固定版本

基线 HEAD：44824b827020377af2be29b5918af7247dbcbc62

已跟踪文件补丁 SHA-256（git diff HEAD -- 本表路径，包含共享文件既有 hunk）：2c679b68b4f96616fecfb50f0295dadd3d1f4152ede17edfdcabdc198f48fe98

文件清单 SHA-256（有序 path/sha256 JSON，覆盖新建文件）：2925425b64853a2872efa6c56564ef4db72059adaebaea707d54d34816ac7dca

| 文件 | SHA-256 |
| --- | --- |
| 前端代码/cashier-v3/src/layouts/CashierShell.vue | 83ab0ddf72c4364c582a3babf64b9c0d25191de15cb96f8c70ab1422bbc55f28 |
| 前端代码/cashier-v3/src/services/cashierV3ActionManifest.js | 99e003c36b713148a13e47c00231fe0c34bbc2636138d3e75db035ac6c58d99e |
| 前端代码/cashier-v3/src/services/cashierV3Bridge.js | 5a03f69a82f7eee353083ab724581dca8e615df7e09efb3f9aad32ccd00a9c50 |
| 前端代码/cashier-v3/src/views/OrderCenterView.vue | 191e7d448cbdc731770b473873c2353e9f29c050c3e57b36a38af61ffd708f9f |
| 后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php | ead39f85e474b6b47e500bb093f6eaccde3285a33f2d0db31314ce09d9944ca0 |
| 后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php | 86ac67d3c890484e4c9297ffec120fe1a1d31864a20e5950f9e57b844ca542e5 |
| 后端代码/app/services/cashier/v3/order/CashierV3ImportedCardRefundServices.php | e86100a6973edae331c0716cb4cbadbfccdba4fbe91e93fa9859e51d6568c282 |
| 后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php | 6027d431bf70221602ef6c7ad256bea67fb30bd6a1039f82add9247bbc2f9d66 |
| 后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php | c7ccd91a79fbc555a66941a05f188d284ded824868063d0b9e780342a261f221 |
| 后端代码/app/services/cashier/v3/order/CashierV3DebtRestorationServices.php | aade3357ec5ebf8fc55ee774536ce5ba03b055509816d820091a0339292e9d1f |
| 后端代码/app/services/query/metric/RegisteredMetricReadServices.php | 6a5f33d36a263f94f0ae48552be57d270b81edb33cc1fe67444cafae1c00f810 |
| tests/cashier-v3/php/r25-imported-card-refund-mysql.php | 42f34b594771abac37063e8679409b9b75c8ef1cc0eeb0cbad5967adc83c0d99 |

产品经理验收入口：本地门店端 → 订单 → 退款记录 → 旧卡退款。验证整数金额、会员候选、退款结果及办理门店业绩。验收前如上述业务文件发生变化，需要重新固定并说明受影响范围。
2026-10-05 发布授权补记：产品经理对本轮明确回复“我测试没有问题,commit,部署瑞昊,push”。上述固定业务文件指纹复核一致；提交时排除 CashierShell.vue 中其他任务的专属服务人多选改动，仅提交第25轮闭环。生产验证采用只读检查，不创建真实退款。
