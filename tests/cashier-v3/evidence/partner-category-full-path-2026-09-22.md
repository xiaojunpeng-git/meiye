# 合作方品项分类完整展示与同类合并：固定验收版本

- 状态：开发及本地自测完成，待产品经理确认；未 commit、未部署、未 push。
- 基线 HEAD：`a8c3a2964d54cfddcc5d6d6d2e50e28905abd36a`。
- 范围：合作方品项汇总、明细及其下钻；不改历史数据、不改收银页面。
- 业务口径：按月份、门店、组织维度、成交时完整分类路径合并。同一级名称但完整路径不同不合并；隐藏的合作方名称不再拆分同一可见分类。下钻精确匹配完整路径，普通分类搜索仍是前缀匹配。

## 文件边界与 SHA-256

| 分组 | 文件（相对源码正本） | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportServices.php` | `9a9e56bb2879d54e64d33bac4e408e97c04c306f3a8f4a3d01dd0d060c37dbe7` |
| 后端源码 | `后端代码/app/controller/admin/v1/report/UnifiedReport.php` | `ba8aebaf35419ec49f53723eb734d61f3e2ca5b4b13242de35fb2ade9fdbc0cc` |
| 后端源码 | `后端代码/app/controller/cashier/v3/Report.php` | `ecde014f4360e0dfbdb91aa0fcc18b8cb955205e91d508543a05f6d08f276067` |
| 后端源码 | `后端代码/app/controller/store/report/UnifiedReport.php` | `d01dc08da48c8395bdd18208a39bf32e257faa963d835af711b22ce33a21427e` |
| 自动化测试 | `tests/cashier-v3/php/first-phase-report-table-projection-contract.php` | `fd16f79d6939ad4933f9e2570624e95b750cbd8cf370639df2424d6d8c56daf1` |
| 自动化测试 | `tests/cashier-v3/php/partner-category-full-path-contract.php` | `4543b7f93beb6205d48c9ac859971221accfa3cc579d82a8756608ce884dd01f` |
| 自动化测试 | `tests/cashier-v3/php/partner-category-full-path-mysql-integration.php` | `e2c76f6deb1d6adbd0a466e825e62ad6deb7d5cb317148bd9afe8cb9f35eab43` |

以上已跟踪文件的 `git diff` SHA-256 为 `8499c49d63041c24fbdbd5af30b64e048fc1b308ade25802865a55edbff1a401`。该原始 diff 还包含服务文件里本任务开始前已有的 `project_count_decimal` 改动；它不属于本闭环，后续只能按本闭环补丁逐块暂存，不能整文件暂存。

前端源码：无。数据库迁移：无。工程脚本：无。生成物：无。本文件为验收证据，不是业务源码。

## 自测结果

- `partner-category-full-path-contract.php`：PASS；同完整路径合并、不同路径分开、隐藏合作方不拆行；1500/500 两个分类的明细金额互不串入。
- `partner-category-full-path-mysql-integration.php`：本地瑞昊数据库只读 PASS；现有拆分卡样本显示完整路径 `六维 / 自营=1499`、`六维 / 自营 / 自营ks=500`，两条汇总各自的精确下钻金额和分类均一致。该本地样本金额与线上截图的 1500/500 不完全相同，不能把本地验证冒充线上验证。
- `first-phase-report-table-projection-contract.php`：7/7 PASS。
- `store-business-report-contract.mjs`：40/40 PASS。
- 改动文件 `php -l` 与 `git diff --check`：PASS。
- 两个已有的宽泛合同测试仍失败于本闭环以外的旧断言：`store-operations-report-contract.php` 要求会员进店去重的旧字符串；`store-operations-organization-dimension-contract.php` 引用已不存在的 `itemAnalysisConsumptionFilters`。本任务未改相应会员进店或品项分析逻辑。

本次服务、控制器新增或修改的关键分组粒度、精确下钻、分类金额边界均有业务约束注释。尚未对瑞昊线上执行本版本验证或发布。
