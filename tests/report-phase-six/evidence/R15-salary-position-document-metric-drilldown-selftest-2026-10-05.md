# R15 薪资报表岗位、单据筛选与指标下钻自测

## 固定版本

- 基线 HEAD：`e42ed6ddcffda6e7ca4b1a6696da02117857498b`
- 已跟踪文件补丁 SHA-256：`7f997d341e4eb2fa855b406dd26015a6553cbe3698eeff0a87ad16a30a6a2374`
- 新增集成测试 SHA-256：`e5bb6639fd81514f478414801d67f9c48b412a6f984ac1d66947d44244e16ea1`
- 本轮仅本地开发及自测；未 commit、部署、改写线上数据库或 push。

闭环文件 SHA-256：

| 文件 | SHA-256 |
|---|---|
| `后端代码/app/services/report/StoreUnifiedReportPhaseSixServices.php` | `5302ec5a1020a9f233e799d810a287a45316fde1a41a7dfcf51359e8d9fbeb4c` |
| `后端代码/app/services/query/metric/ServiceCustomerMetricReadServices.php` | `5870e6c0da5ce28bcc19fdcad3fc1a92ede3273456d54d23df26edd55c6b315c` |
| `后端代码/app/controller/admin/v1/report/UnifiedReport.php` | `5aba3c70cbf1e3ad18d67f325898a46427889393c619a98a58aed8b50253c632` |
| `后端代码/app/controller/cashier/v3/Report.php` | `7312a49ed992de821144f0f79938bee84e080ddcdec90ebbe8696028336b287c` |
| `后端代码/app/controller/store/report/UnifiedReport.php` | `7a922e2bff6d5bb5fc596117ab0617c955d02d073243a722dad2ee517f582761` |
| `tests/report-phase-six/php/service-contract.php` | `860183436be4df75508bf83abe505a94a78c011f86691b540f224ccc1087905f` |
| `tests/report-phase-six/php/salary-metric-drilldown-integration.php` | `e5bb6639fd81514f478414801d67f9c48b412a6f984ac1d66947d44244e16ea1` |
| `tests/cashier-v3/php/independent-service-customer-allocation.php` | `3e62d2880935bea09ffae482563845e61090c439b19bae5b06bc2008ec6870c1` |

## 范围与取值

- 两张薪资表在“员工”后显示“岗位”。它读取员工目前在业务门店的有效任职；无有效岗位显示“-”，不用于重算历史业绩或服务客数。
- 明细表增加“单据号”输入框，按订单中心对应的订单编号筛选；页面、合计和导出共用服务端结果。
- 汇总表的项目数、服务人次、服务人数、消耗可以按当前员工及门店下钻。服务人数仍由同一服务客数读入器跨日期去重，再将份额定位到一条可核对的明细；普通明细不恢复已移除的客数列。
- 两张表的“列名取值来源”已逐列核对并改成业务语言，说明岗位当前任职、项目分摊、本人/朋友/无会员顾客的客数规则和作废影响。

## 数据架构自检

- 权威来源：已完成服务、有效人员业绩及订单编号；工资明细以员工在一条业务明细上的有效分配为粒度。岗位只读取当前门店任职，和发生时员工姓名区分。
- 时间、事务与冲销：本轮只读，按已有业务日期筛选；没有新写入、事务、幂等键或 Outbox。后续作废、人员调整继续由现有有效事实/反向事实过滤承担。
- 权限与历史：员工/门店稳定 ID 在后端授权门店内校验；筛选、下钻及导出不能扩大权限。历史金额和人员名称不随当前岗位变化重算，也没有修改旧数据。
- 性能：岗位仅在授权门店、所选员工范围内按每批最多 300 人读取；单据号沿用现有批量订单引用，未新增逐行数据库查询。无需迁移或新索引。
- 对账：项目数、服务人次、服务人数、消耗的汇总单元格与对应明细合计逐项比较；服务人数去重结果与对应单笔明细份额合计一致。

## 验证

- 8 个修改/新增 PHP 文件 `php -l` 均通过；`git diff --check` 通过。
- `tests/report-phase-six/php/service-contract.php`：通过。
- `tests/cashier-v3/php/independent-service-customer-allocation.php`：12 项通过，含新增的服务人数明细份额对账。
- `tests/report-phase-six/php/salary-document-column-integration.php`：2 条真实事实通过；页面与导出订单号、分类一致。
- `tests/report-phase-six/php/salary-later-reversal-integration.php`：排除 144 条跨日冲销事实，19 次分类下钻对账通过。
- `tests/report-phase-six/php/salary-metric-drilldown-integration.php`：基于本地瑞昊数据副本、江苏江都店 2026-10-01 至 10-05 的 32 次四指标下钻均与汇总相等；岗位列顺序、单据号过滤与普通明细列保持正常。
- 本地 `18091` 页面：江苏东台吾悦店汇总显示岗位及四个可点数值；点击服务人次、服务人数进入带对应列的明细；输入 `FW100300260` 后只显示对应单据；两张表的“列名取值来源”弹窗已检查。浏览器恢复至原汇总页。

## 文件分组

- 前端源码：无（复用报表元数据渲染的现有控件和下钻路由）。
- 后端源码：`StoreUnifiedReportPhaseSixServices.php`、`ServiceCustomerMetricReadServices.php`，以及平台/门店/收银三个报表控制器的参数白名单。
- 数据库迁移：无。
- 自动化测试：`service-contract.php`、`independent-service-customer-allocation.php`、`salary-metric-drilldown-integration.php`。
- 工程脚本、生成物：无。验收证据：本文件。

开发与自测完成，待产品经理确认该固定版本验收。
