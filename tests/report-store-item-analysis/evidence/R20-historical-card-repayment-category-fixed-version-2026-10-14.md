# R20 历史卡补交归入门店品项分类：本地固定版本

- 基线 HEAD：`486f8c7a`。本记录只固定第20轮待验收工作区；未 commit、未部署、未 push。
- 前端源码：无。后端源码：`app/services/query/metric/RegisteredMetricReadServices.php`、`app/services/report/StoreUnifiedReportServices.php`。数据库迁移、工程脚本、生成物：无。自动化测试：`tests/report-store-item-analysis/php/contract.php`、`tests/report-store-item-analysis/php/historical-card-repayment-category-readonly.php`。验收证据：本文件。
- 文件 SHA-256（完整当前文件）：读数器 `f69155be8bc9689afa989e72fde60fcb83c31c1842986341419ed52c5ff7a4d8`；报表服务 `983890c84e9c2c8076f39f8b590c08ed15bacab0bfa428ac526a0a8073b552aa`；契约测试 `ba0275e1d6d1901c72d4ea373e842715fd2dfa072ad0d8a75469727cb19b96a4`；新增回归测试 `a2d1e999afe1e023cdbf363986a274695cbd6ce3775b24dc252207f5398889da`。
- 已跟踪三文件相对 HEAD 的工作区 diff SHA-256：`8e5ccea474f4f37220a20e5788fbbb00f5cd4137c51c5e11abc2ced4fa9b364b`。这些共享文件在第20轮前已有其他未提交改动；此指纹仅固定整份工作区差异，不冒充第20轮独占补丁。提交时必须隔离暂存第20轮代码。

## 业务与架构核对

- 权威收款是成功补交的支付事实，每笔支付只计一次；原迁入卡订单及其卡内项目仅提供商品 ID 和原金额权重，当前平台分类只用于报表展示。统计日、门店和会员仍取收款事实，不回写销售、收款或分类历史事实。
- 仅在原单为单张迁入卡、债务与原单/会员/门店一致、卡内项目均为有效迁入明细、项目金额之和等于原单金额、所有实际收款等于本次补交金额且不存在正常销售分摊时分类。多卡、断链、金额不平或无有效当前分类仍归“未分类”；同一收款拆分后的分合计保持分精度一致。
- 统一指标 Reader 保持服务端门店/人员范围与日期、分类筛选；200 笔一批按补交号、原单号、支付号既有索引查验，未引入前端计算、新业务事实、迁移或历史回填。作废与退款沿用原有有效事实过滤及负向事实口径。
- 瑞昊生产 `BJ26101400007` 只读核对：成功补交 87200 分，原单迁入卡、单卡单项目，原单和项目金额均 800000 分，支付合计 87200 分且无正常销售分摊；项目当前平台分类 ID `26332`，路径“生美/卡项”。本轮没有在线执行新代码，线上报表仍待发布后验证。

## 自测与边界

- 新增只读本地 MySQL 回归 PASS：历史卡补交进入当前项目分类；分类 ID/路径筛选保留该笔；门店现金总额不变；对账失败退回未分类；品项分析分类合计与统一读数及导出一致。
- 现有 `tests/report-store-item-analysis/php/contract.php` PASS；读数器、报表服务和新回归脚本 PHP 语法检查 PASS；`git diff --check` PASS。
- 共享退款读数的只读冒烟：2026-10-13 门店 23 退款汇总与分类明细均为 33300 分，PASS。旧 `refund-local-mysql.php` 因宿主机缺少专用测试数据库环境变量未运行，不能记作通过。
- 本次补充或更新业务注释：`RegisteredMetricReadServices.php`（历史卡分类成立边界与原事实不变）。`StoreUnifiedReportServices.php` 更新了用户可见的分类取值说明；新增测试注明只读边界。
- 本地浏览器当前登录门店并非回归样本门店，未把空页面视作页面业务验收；待产品经理以固定文件版本确认后，才进入本地提交。部署和 push 尚无第20轮授权。
