# 门店品项分析退款冲减：本地自测

- 产品决定：本报表现有“现金业绩”直接扣退款金额，不另加退款或经营净额列。成功退款按退款业务日冲减当日和累计；商品或卡内项目按当前可验证的平台分类归入对应分类列。合作方分成按原成交比例反向冲减，分成后业绩继续由净现金减净分成计算。
- 固定基线 HEAD：`5754b8b5a1c82d3e225825c826c3cfe16569d709`。本记录是本地开发自测，尚待产品经理对当前文件版本确认验收；未 commit、未部署、未 push、未写线上库。
- 已跟踪文件的限定 `git diff --binary` 补丁指纹：`2fb2384d5f76467992eb72aa197c5eb3cdef11f56c236a6125dc795fc6d5273b`；未跟踪的读取器及测试文件以表中的完整文件 SHA-256 固定。

## 文件边界与 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `e5a9620ffb7394753443f502187e54acaecfb6dfd45bcc3fb17e8c210fcb6ae5` |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportServices.php` | `13d99e3fe8e1634a884900d48d0bb1232e36a5c0a92f8fd1590ca9eca0033a28` |
| 后端源码 | `后端代码/app/services/report/StoreMemberConsumptionRefundReadServices.php` | `9ab3745c2fc9c0c7e0ad86a96c2fada25d92a5f3d666698fbfdacb350541b897` |
| 自动化测试 | `tests/report-store-item-analysis/php/contract.php` | `ebc5090db56425f5d75eb3210933a328bb7ffcace54df00f28d3b9db9cb2aa24` |
| 自动化测试 | `tests/report-store-item-analysis/php/refund-local-mysql.php` | `c33383cdd4ad9cfeaf8aac60c1ba98aa1f339208766e2ed3a03ffdf511a5266e` |
| 自动化测试 | `tests/report-refund-visibility/php/partner-refund-rollback-mysql.php` | `c0fceaf55da51b3fabc8b4f6191a6c8468548f88dcb76ae57a3c6ba64bf4d2f6` |

无前端源码、数据库迁移、工程脚本或生成物变更。后端新增/更新的业务边界注释位于上述三个后端源码文件：退款指标与独立退款去重、按原商品归类、金额及原分成比例反向处理、分批读取限制。

## 数据架构自检

- 权威来源与粒度：正向收款及原销售/卡内项目事实保持不变；成功退款使用有效负向支付事实，一笔独立退款只扣一次。分成使用原销售或对应卡内项目的比例快照，不从当前配置重算比例。
- 时间与状态：按退款成功业务日归属，失败/作废退款不计；原销售事实及原日期不覆盖。未新增写命令、事务、幂等键、Outbox 或聚合写入，故并发和重试写入边界保持现状。
- 分类与权限：商品当前分类仅决定报表展示归属；找不到可信商品时仍在“未分类”，不按名称猜测。门店、组织、员工范围由已授权报表入口注入，查询、合计、筛选与导出走同一后端结果。退款读取分 500 条批处理；未做远程 EXPLAIN，部署前仍需在目标实例核验累计范围耗时。
- 对账：当日和累计现金 = 已登记正向现金 - 销售关联退款 - 独立卡项/单据退款；合作方退款按原比例冲回，分成后业绩 = 本表净现金 - 净分成。历史原销售关系缺失时不伪造分成。

## 验证

- `bash tests/report-store-item-analysis/run-static.sh` 通过；两个改动读取器 `php -l` 通过；`git diff --check` 通过。
- `ROUND3_TEST_DATABASE=ruihao ROUND3_TEST_PASSWORD=... php tests/report-store-item-analysis/php/refund-local-mysql.php`：本地真实退款 `TK2610130001`（333 元）当天只扣一次；累计与统一指标及独立退款源对账；分类筛选、门店权限、合计和导出一致。
- `ROUND3_TEST_DATABASE=ruihao ROUND3_TEST_PASSWORD=... php tests/report-refund-visibility/php/partner-refund-rollback-mysql.php`：直接合作商品与卡内合作项目各 100 元的退款夹具分别按原 30% / 40% 分成冲减；分类金额各仅减少 100 元；两笔夹具均回滚且未持久化。
- 本地 18091 真人查看：扬州欧尚店 2026-10-13 “现金业绩·当日”为 `-333`，“分成业绩·当日”为 `0`，“分成后业绩·当日”为 `-333`；列名取值来源已说明退款冲减。累计现金为 `9326`，由当时本地数据与回归脚本的完整区间对账得出，后续业务数据变化会改变该数。

当前共享工作区存在大量其他任务改动，本次没有暂存或清理它们。验收时以上述文件 SHA-256 核对受影响版本；若文件变化，需重跑对应自测并重新固定。
