# R41 成交人数原单门槛：本地自测记录

状态：开发与本地自测完成，统一隔离候选待产品经理补验；第 41 轮未 commit、未部署、未 push。两站现网仅做了只读基线核对；仅本机瑞昊开发库应用了门槛配置表及默认版本。

## 范围与基线

- 唯一源码正本：`美容源码/`；开始时 HEAD `88267a43f830a798bb484038911e5202d62dc26b`。工作区存在大量其他任务未提交改动，不属于 R41。
- 规则：原销售单独立累计有效现金收款；A 来源原单默认 1000 元、非 A 默认 500 元，门槛在原单结账时固定。首次跨门槛按收款业务日计 1，补交跨店归原销售门店；同会员同日全实例只计一次，跨日可再计。游客、退款、旧导入及旧卡录入原单补交不推动资格。多分类原单按首次达标时现金分摊最大的分类归属，平手按商品明细顺序。
- 平台系统设置可版本化调整两类门槛，整数元校验与乐观版本保护；旧原单不随调参漂移。
- 两个数据菜单同名成交人数列复用统一资格事件；其余金额、进店、服务、复购等指标保留各自原有口径。查询、下钻与导出复用对应报表后端行集。

## 自测

- `php -l`：R41 新增控制器、两项服务、五个受影响报表服务及平台路由均通过。
- `r41-deal-people-order-qualification-contract.php`：通过；覆盖原单跨日累加、一次资格、会员日跨店去重、退款不影响资格、游客排除、完整分类最大现金分摊、同额顺序及配置生效时点。
- `pre-sale-bdegh-contract.php`：通过；旧月度收款投影不再自行计算成交人数，金额、二次到店及跨日有符号明细仍通过。
- `r41-deal-people-policy-local.php`：本机开发库事务测试通过；两项门槛保存、并发版本冲突与小数拒绝均验证，外层事务回滚。
- `r41-deal-people-cross-store-local.php`：本机开发库事务测试通过；临时把一笔达标补交从原店 41 改到付款店 108，资格只出现在原店；`finally` 回滚，回查统一事件仍为 4 条。
- `r41-deal-people-partner-local.php`：本机开发库事务测试通过；临时给一张已达标原单赋合作方归属，分类汇总 1、人数明细 1、导出合计一致。`finally` 回滚，回查临时归属行数为 0。
- 本机只读样本 2026-10-01 至 2026-10-17，原店 41/108：统一事件 4 条；市场业绩合计成交人数 4、市场明细合计 4；运营生美品项合计 4，其中“仪器放松”汇总 2 与下钻 2 一致。花园、六维与非 A 来源在该样本期没有正向达标人数，仅完成查询无异常的零值检查。
- 平台前端构建通过；既有构建体积警告不影响产物。受影响文件 `git diff --check` 通过。

## 代码质量边界

- 删除本轮已完整替代且无生产调用的固定 500 元预售人数计算、合作方旧人数现金行及部分报表私有重复计数分支；保留仍用于金额、二次到店或历史证据的逻辑。
- 本轮新增或修改的资格、参数版本、权限、统计、跨店与分类分支均补充或更新了业务边界注释，涉及 `StoreDealPeopleQualificationServices.php`、`StoreDealPeopleThresholdPolicyServices.php`、`DealPeopleThresholdPolicy.php`、五个报表服务、路由及迁移。
- 没有清理其他任务源码、历史验收证据或仍被调用的兼容路径。跨店及合作方正向路径已由本机回滚事务验证；固定验收候选时仍需完成共享文件改动隔离。

## 统一隔离候选与防回退

- 当前 HEAD 原缺两站已上线的两条平台路由；经产品经理授权，已单独归档为 `2ea0f559`，其 `admin.php` SHA-256 `fa4b64d0de4db2f3d8593365fa17405ccc13791dede8df877110ab6c7ba731e8` 与两站现网一致。
- 两站现网平台包是已验收 R5 提交 `3aeaa2ed` 的包；经产品经理追加授权，当前 HEAD 原缺的四份平台前端源码已单独归档为 `a8365695`。该提交的平台源码子树 `1935bc5760b029c4f3484c7d40397ec43467d814` 与 R5 已上线提交完全一致，不将其算作第 41 轮优化。
- 隔离候选源码树（不计本证据文件）`33bdd266b220a988d0fed3b4234f2fc97a2b0014`。相对已上线 R5 平台源码，候选仅改 `src/api/system.js` 与 `src/pages/setting/setSystem/index.vue`；其他任务工作区改动不带入。主入口 `system.html` SHA-256 `6e86a6516684b35b3b3d5fd5e2d667962a2cd31f4b374baa5402a2caa22d0d1b`，主 JS `app.df790c3a.js` SHA-256 `71d6509d3849c5ef9b4c776bc6d231a2095e9fa658590aec3a2921f34777e0b3`，CSS `app.e7380eaa.css` SHA-256 `c0aa73cc309ebca5f33ea8780ff09cf9b2c85dee79850d4c3b9a6e55cb4720fb`。从只读构建快照执行平台生产构建通过；旧源码 ESLint 及资源体积告警仍存在。
- 混合文件按暂存补丁隔离：候选 `StoreUnifiedReportPhaseTwoServices.php` SHA-256 `3bbfaf47e7d1d69dc9679ee9cb00729fcdc642d554bc1962b1c4b937a571fb62`，排除其他任务的方法位置移动和无关顺序调整；候选 `pre-sale-bdegh-contract.php` SHA-256 `176e7fda20e4d2e095e49b733800702489384ae61d4625e621ace3dd7f5164e1`，只取本轮两处断言。候选 `admin.php` SHA-256 `597e09486279895f26aedbdc45ca12c4fb3e92ab90a1097437e460a43ccfd9cf`，保留已上线两条路由并只新增本轮门槛路由。
- 本轮新增 `scripts/r41-instance-migration.php`，用于两站独立库校验、全库备份、固定 SQL 升级与版本记录验证；PHP 语法通过。两站现网 `ruihao`/`mohe_008:3307` 的目标表迁移前均不存在。门店端正式与预览仍为相同的 R40 前端入口和资源，本轮无门店包变化，按授权只核验不重发。
- 隔离暂存补丁 `git diff --cached --check` 通过，九份后端受影响 PHP 的候选 blob 均通过 `php -l`；核心资格与预售合同复跑通过，门槛版本、跨店补交和合作方汇总/明细/导出三个本机事务测试均复跑通过且回滚。上述自测不能替代产品经理对固定候选的确认。

## 受影响文件 SHA-256

以下为本轮早期自测时工作区文件指纹，**不是**隔离候选的完整文件清单；共享文件含其他任务已有改动，提交必须以本节的隔离候选及其暂存差异为准，不得直接整体暂存。

```text
bf773dc16669401a6027a9df22462fab694f3b98ec26b52d47b4cfef099a343a  前端代码/admin/src/api/system.js
99fca7d0d44c16105ca9034a6db81a5faaa6a535bac0dc903fa58ec4fd774b47  前端代码/admin/src/pages/setting/setSystem/index.vue
63ac0fc86e344b362403569da04c5a9e7b4741a25109fcf6380789d4bfbc2be2  后端代码/app/controller/admin/v1/system/DealPeopleThresholdPolicy.php
053249f83b285380eb02d3cc59f965618738e37f37bd34be8a3c56b426f5a737  后端代码/app/services/report/StoreDealPeopleQualificationServices.php
d8f3ca5ed1d20a442929fa4ebc7a8d43022e961ca5713775cc83a861aaba8298  后端代码/app/services/report/StoreDealPeopleThresholdPolicyServices.php
dca846e78e7b44ad79fcb72c69af1fcedc4801f8f643eb36b6d2af028da60d88  后端代码/app/services/report/StoreUnifiedReportServices.php
f39ceefbde40fa2ae370810874db38e5197b4892c8975f7be07d9616bfe7025a  后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php
e5c3f1d96198da14bcde6d36eb56b852b1d87b13d092559e1117edb9381cd22b  后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php
c374d9f7739dd70b101b858872389a88cf3756f13955d81f9a4f7c0a5691230a  后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php
6055219edf9cd59e03d140ac56e36cf73e93fdda862707fc8a56da194acb2fd9  后端代码/app/services/report/GroupManagementDashboardServices.php
597e09486279895f26aedbdc45ca12c4fb3e92ab90a1097437e460a43ccfd9cf  后端代码/route/admin.php
d73cc45d0383435c844d77934ee0eb3a7a724a59238a7e7802be115bec94db5e  后端代码/database/upgrades/2026-10-17-成交人数原单门槛/02-正式升级.sql
598542f113deb602a92d5402f8a355c26f3a1f6b0aaa74a4a0590e9a4bf03ad9  tests/report-phase-four/php/r41-deal-people-order-qualification-contract.php
fab26b2e9e656a17fad1ec24520cba010f4900e572807c52bd8f32ca0d1af1f5  tests/report-phase-four/php/r41-deal-people-policy-local.php
f9314f67f85ac28e05771eba0d4cd3027af9df3a2c1eed9561333d873f7143e7  tests/report-phase-four/php/r41-deal-people-cross-store-local.php
27d4519b421892ae20bd8d9986714fc1469bdb1c03a66564cb19394ef71548c7  tests/report-phase-four/php/r41-deal-people-partner-local.php
eacce867fde318907db76bd573c1cd312a802329438fc4050cd3705fcc7e6303  tests/report-phase-four/php/pre-sale-bdegh-contract.php
e0ad3f79aec0dbbe58d77ad5a11dc538f5c40765200e8a6fa7252ee76f11f59c  ../XJPMD/20-成交人数算法.md
```
