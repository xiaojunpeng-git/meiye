# R40 三个看板本地自测

- 基线 HEAD：`4905abc3e652df517078bda1fc1df8bb7382ebca`。本轮改动仍在唯一正本的工作区，未提交、部署或推送；线上实例、回滚点和远程指纹不适用。工作区另有其他任务改动，不能把整个工作区当作本轮提交清单。
- 会员看板：移除顶部商品分类筛选，使用统一周期控件；分类根节点、顺序和显隐与集团看板一致。会员/新客分层按实际业绩净额逐档累计，高档会员同时计入低档，新客仍排除来源 A。全范围查询从逐条历史服务事实改为数据库按会员最近有效服务日期归并，避免 PHP 128 MB 内存耗尽。
- 会员金额：消费、服务区金额及人均/项目均金额、分类、排行、分层均使用统一登记的实际业绩。非体验销售范围不变，游客和无可核实销售关联的独立收款不混入消费分类。服务人数/项目数仍按服务完成日；服务区金额显示同期收款/退款业务日的实际业绩，而非再次把服务消耗额当作现金，因此消费与服务区金额可能相同，人数与金额不是逐笔配对事实。两个排行面板现读取同一实际业绩金额，属本轮指定口径。
- 商品看板：“销售业绩”本期、同比和环比改读统一登记的实际业绩；消耗业绩、品项毛利和库存指标保持原业务定义。
- 集团看板：顶部六张卡不变。经营趋势仍保留蓝色实际业绩和橙色消耗业绩两条曲线（产品经理明确选择）；下方分类卡、项目/产品排行及今日客情金额使用实际业绩，目标进度及排行原本就使用实际业绩。分类读取保留退款负值，按退款业务日冲减；现金业绩读取的原正向卡片行为不变。
- 统一收款边界：实际业绩为现金收款减现金退款；“其他收款”和“旧卡录入”均不算现金，含其原始收款与冲销。历史事实未修改。会员和商品看板分别保留原有非体验消费、非卡商品销售范围，并在列名说明中明确。

## 本地验证

- 合同与语法：会员合同 16 项、累计分层 6 项、商品现金来源 6 项、集团本轮合同 8 项、商品看板静态套件通过；会员、商品、集团服务及注册指标读取层 PHP 语法检查通过。门店端 `npm run build` 通过，预构建快照 70 项；当前共享工作区构建主 JS `index-300ab837.js`、CSS `index-c607b5fa.css`，均为未提交生成物，包含其他任务的未验收工作区改动，不是隔离发布候选。
- 只读业务对账：会员 102 店本月及全年查询均在 PHP 128 MB 限额下通过；商品看板门店 68 在 2026-10-14 的销售实际业绩与登记指标同为 150,000 分；集团分类卡门店 68 的现金和实际均为 6,060,000 分。门店 23 在 2026-10-16 的分类现金为 1,170,600 分、实际为 1,160,700 分，确实扣除 9,900 分退款；来源人数/金额只读回归通过。
- 本地页面：18081 嵌入 18091 的会员、商品、集团看板均可加载。2026-10-17 的会员消费和服务区同期实际业绩均显示 ¥11,130；商品看板本月销售业绩显示 ¥5,030,304；集团顶部六卡显示现金 ¥11,130、退款 ¥0、实际 ¥11,130、消耗 ¥427、完成服务 8 项、消耗单价 ¥53，下方分类与来源均显示实际业绩，橙色消耗曲线仍在。检查了桌面页面截图与可访问文本。
- 既有报表整套脚本 `tests/report-phase-seven/run-static.sh` 在无关的 `store-target-dashboard-contract.php` 旧静态断言处失败；其被测门店目标读模型另有本轮未触及的工作区改动。本轮会员合同与集团稳定合同在该脚本中已通过，未把整套脚本记为通过。首次选取的 10 月 15 日撤销分摊样本被统一正常数据范围排除，已改用真实纳入指标的 10 月 16 日退款样本。
- 本地源码文件级版本供产品经理确认，完整 SHA-256 如下。正式提交前还须核对当时 HEAD、文件级差异与其他任务对共享读取层的变更；本记录不代替隔离构建或跨任务发布防回退门禁。

## 文件边界

- 前端源码：`前端代码/cashier-v3/src/views/MemberDashboardView.vue`、`GroupManagementDashboardView.vue`。
- 后端源码：`后端代码/app/services/report/MemberManagementDashboardServices.php`、`ProductManagementDashboardServices.php`、`GroupManagementDashboardServices.php`、`后端代码/app/services/query/metric/RegisteredMetricReadServices.php`。
- 自动化测试：`tests/report-phase-seven/php/member-dashboard-contract.php`、`member-dashboard-cumulative-tiers-contract.php`、`round40-member-dashboard-broad-scope-readonly.php`；`tests/report-product-dashboard/php/static-contract.php`、`round40-cash-source-contract.php`、`round40-actual-sales-readonly.php`；`tests/report-group-dashboard/php/source-deal-people-local.php`、`round40-actual-performance-contract.php`、`round40-actual-performance-readonly.php`。
- 数据库迁移、工程脚本：无。生成物：门店端 `dist/`，未纳入源码。验收证据：本文件。
- 本轮注释更新：会员、商品、集团看板服务，注册指标读取层，以及会员看板页面；解释事实粒度、净额冲销、日期归属和分类边界。

## 文件 SHA-256

| 路径 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/views/MemberDashboardView.vue` | `e3c84e1454c70fdc9db2899ee0a1ea196e1edd124c6fe1d947d0832882da595e` |
| `前端代码/cashier-v3/src/views/GroupManagementDashboardView.vue` | `2184d60977bb548a9d185506dd5e916544736de962fcd9e3693a43936f290f67` |
| `后端代码/app/services/report/MemberManagementDashboardServices.php` | `3517b589763902f73ddbdf6ae66c6a51697671b40a700271e593b11faee2e278` |
| `后端代码/app/services/report/ProductManagementDashboardServices.php` | `23b6c4ea0e59e0c31b9e7fd098ffc2277d9698ececa17292b16945e8d9b0461c` |
| `后端代码/app/services/report/GroupManagementDashboardServices.php` | `e77ba0cc57dac8dba935a29559e43769ea9706de296a9f36d779ad7bf3360ea6` |
| `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `ee4eeae36b90feb7985d2384592d1bfe0282fc626b08074de6a2cbefc7f969b8` |
| `tests/report-phase-seven/php/member-dashboard-contract.php` | `bbbc02eacba6171be0bd15b0ce8d18396cb1e3bace64694e0456ad732d181b9c` |
| `tests/report-phase-seven/php/member-dashboard-cumulative-tiers-contract.php` | `b8289dda0fb6cd14896794d7c8438e62caa67b815c0e4937666da7a4c6945dd9` |
| `tests/report-phase-seven/php/round40-member-dashboard-broad-scope-readonly.php` | `b6ab502a72eff8f61f88aa44cce7dd710413dd74d8afe1724239ffdeb2c768ce` |
| `tests/report-product-dashboard/php/static-contract.php` | `ab022a2bd711ab3a3933371595aa54854d02a6afdee0c655338528a7acbdd227` |
| `tests/report-product-dashboard/php/round40-cash-source-contract.php` | `655c03d8b4f821ba90d7af11ffa89e3f115aa0af966ff25f610e3306044ef7e1` |
| `tests/report-product-dashboard/php/round40-actual-sales-readonly.php` | `41e9b5a9fb0077cd8bcef390a4fab635524e1ef2bb37908c0f7f2f039e0b94f4` |
| `tests/report-group-dashboard/php/source-deal-people-local.php` | `269bbee87703f8882e7be05e3269c22510049ffb66d35cc8468040201516bb4c` |
| `tests/report-group-dashboard/php/round40-actual-performance-contract.php` | `5c5401cfdc2c4ca24ba52dabe512f716a41797824ffaf414d86ad9ddb164c068` |
| `tests/report-group-dashboard/php/round40-actual-performance-readonly.php` | `fb8581f927994e452bf6efc51709bf3fade002db858f875642408bf040f1d069` |
