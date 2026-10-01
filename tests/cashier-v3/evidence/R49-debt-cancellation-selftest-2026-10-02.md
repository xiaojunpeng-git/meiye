# R49 取消欠款：固定版本与本地自测

状态：产品经理于 2026-10-02 明确“我测试没有问题,commit,部署瑞昊,push”，确认本固定版本验收通过并授权本地提交、瑞昊部署和线上验证通过后的 push。此前浏览器控制中断及未完成代操作的事实保留，不改写为 Codex 完成整笔页面测试。

## 固定范围

基线 HEAD：def6d6ed72eb9b6cfe54b1f2209ddcaeb9c54b38。
验收标识：R49-debt-cancel-local-20261002；以下文件 SHA-256 共同定义该版本。其他脏工作区改动不属于本闭环。

前端源码：OrderCenterView.vue、CashierShell.vue、cashierV3ActionManifest.js、cashierV3Bridge.js。
后端源码：取消欠款服务、生命周期模块、补交作废服务、订单中心记录查询服务、动作清单、会员订单模块。
数据库迁移：2026-10-02-收银V3独立取消欠款（说明、升级 SQL、验证 SQL）。
自动化测试：tests/cashier-v3/php/r49-debt-cancellation-mysql.php。
工程脚本：无新增。
生成物：本地 cashier-v3/dist 构建产物，未作为源码或 8080 发布包。
验收证据：本记录及 R49-debt-cancel-required-reason.jpg。

## 业务口径与数据边界

- 仅取消剩余欠款；不改变原订单、原欠款金额、已补交金额或既有收款事实，不生成收款、余额、业绩。
- 原欠款行是权威状态，新增独立取消事实保存原金额、已补交、取消金额、原因、操作人、门店及来源快照。
- 状态 3 为独立取消，状态 2 仍为原单冲销失效，不混用。
- 必填原因；正常列表及会员待补交总额排除取消数据；全部列表、详情及共用查询口径的导出保留记录。
- 取消后不能再补交，亦不能作废已补交记录，以免恢复已取消欠款。
- 后端按可信操作人及当前门店核权，不信任前端权限；事务内锁欠款行，比较本次确认的剩余金额快照。
- 重复请求沿用命令幂等键；取消事实及业务事件同事务写入，数据库唯一约束防重复。无需工作台版本或草稿。
- 唯一欠款索引及会员、门店日期索引支持历史查询；无历史数据回填或跨客户数据操作。
- 修改业务逻辑的上述前后端源码均补充职责、金额/权限/冲销或上下文边界注释。

## 实际本地验证

本地后端 mohe-cashier-app，数据库 SELECT DATABASE() 确认为 ruihao；仅本地执行新增审计表迁移。目标线上实例、生产备份与线上迁移：不适用，本次未连接或改变远程实例。

MySQL 业务测试最新执行：19 项 PASS，R49_DEBT_CANCELLATION=PASS。
覆盖：空原因、过期剩余金额、跨门店、已结清及原单失效拒绝；1000 欠款已补交 300 时仅取消 700；未补交时取消 1000；事实与事件；重复请求；取消后作废补交拦截；正常/全部筛选；会员总欠款扣除；收款、业绩、余额事实不变；金额分精度；无工作台/草稿依赖。
所有业务测试写入均在外层事务回滚，验证原欠款恢复且不留下测试取消事实，没有取消现有业务欠款。

其他回归：
- 前后端动作登记同步：55 项通过、0 项失败，NETWORK_ACTIONS=32，DYNAMIC_SITES=0。
- debt-repayment-unique-snapshot-contract.mjs：PASS。
- recharge-debt-repayment-frontend-contract.mjs：PASS。
- recharge-normal-scope-contract.php：PASS。
- 受影响 PHP 语法检查及 git diff --check：通过。
- 后端模块自检：通过，取消动作注册生效。
- Docker 当前 cashier-v3 源码 npm run build：通过，1986 模块；index-917b206a.js、index-f1934944.css。保留既有 chunk 大小警告。

浏览器真实路径：18091 → 欠款管理 → 取消欠款 → 空原因确认。可见剩余金额 1000 元、必填原因拦截及操作按钮，未执行成功取消原业务单。截图：R49-debt-cancel-required-reason.jpg。
成功取消业务路径已用真实 MySQL 与实际服务完成并回滚；尚未进行并发压力测试，也未以浏览器完整成功取消替代产品经理验收。

## 待验收

在 18091 刷新后打开欠款管理，核验取消原因、剩余欠款取消及全部记录保留。产品经理确认前不得提交；本地验收不构成部署或 push 授权。
将来部署应先执行该实例迁移，再部署依赖代码。若已有真实取消数据，回滚必须保留取消状态、审计及相关拦截，不能直接把欠款改回待补交。

## 文件指纹

```text
06977419bf133da384216c8ed024621d8dac64d4268665ad26c3f2850b3dad10  前端代码/cashier-v3/src/views/OrderCenterView.vue
b88c617cc0914d1d43e1b4290ac8023576f39c161e3834189c28ab8127cd041e  前端代码/cashier-v3/src/layouts/CashierShell.vue
32e303fd42ab4cd72d0c425cf348c489b8bc2a33e001349d97b812eba2248aaa  前端代码/cashier-v3/src/services/cashierV3ActionManifest.js
bc2769eefc1464e9b97903f97d3b25d40d2c93d64942f47cbe82864ddff28563  前端代码/cashier-v3/src/services/cashierV3Bridge.js
0a36bf0bbf53e264c6b081635aa0b3feb0f34b40fa93706b74e2445a2f33ef4f  后端代码/app/services/cashier/v3/order/CashierV3DebtCancellationServices.php
12fb53802a2bcee183cdb4cbc4a8a4e6abefdadf1405c543662362736f65f94f  后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php
ed3b017d294b221b6f09a666288a7eb5a32d8c45a80bd0f39d168e8a8113de4d  后端代码/app/services/cashier/v3/order/CashierV3OrderCenterVoidServices.php
86197284509a04b97d45df50541e22c4d1ba36001d53237f4088503798d5abed  后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php
5e982f175b8b2efe18fc092f29c1888b752a67293eb707d3fb8ad75a53c6eeb8  后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php
796b030f43f154b45c5129a497fe67a2044655f99291211edad6d4f3da2d08aa  后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php
0d54ccc4f45739ae4f705edd7b253ff18e75f0556e9c570f6176a1385d6c346c  后端代码/database/upgrades/2026-10-02-收银V3独立取消欠款/00-升级说明.md
feed64b6ffd3ef1f103640b9e061b9667e04cd8f24417960ef6395a31134c59f  后端代码/database/upgrades/2026-10-02-收银V3独立取消欠款/01-升级.sql
f6dec992326adcf72e36068fe35dc8aa58548eaf0fccdc9e21a4fed0408556bf  后端代码/database/upgrades/2026-10-02-收银V3独立取消欠款/03-升级后验证.sql
7bb43530ff8babdb0a81cae377867a8c6faa437aa04e741b4e14f196b50bf03a  tests/cashier-v3/php/r49-debt-cancellation-mysql.php
```
