# R39 库存统计周期条件兼容修复自测

- 基线 HEAD：`e1cd593c6cf03e30332e024ec5f30a9047764d6e`。上次提交的统计聚合代码只接受单条 `between`；统一查询周期控件实际发送 `gte` 与 `lte` 两条 topFilters，规范化后为 `greater_or_equal` 与 `less_or_equal`，因此后端拒绝了本月查询。
- 修复：统计日期解析支持周期控件的两条 AND 边界条件，以及已有的 `between`。多个边界取交集，结束日仍由服务端查询截止日收窄；其他位置的日期条件继续拒绝，防止先聚合后筛选导致口径漂移。

## 数据架构自检

- 权威源仍为不可变库存批次流水事实，最小粒度、成功状态和 `business_date` 统计时点未变。没有业务写入，不涉及事务、并发、幂等、Outbox 或冲销分支改造。
- 商品和 SKU 的历史名称快照、服务端仓库权限、成本权限以及结果/导出同一后端投影均未变。日期只在事实查询前缩小范围，不能扩大当前账号仓库范围；查询仍使用既有事实日期索引。历史数据无需迁移或回填。
- 本地对账：同一门店/仓的页面式周期条件（`gte/lte`）与 `between` 返回相同结果；对应整月入库 4 行、出库 1 行。9 月 28 日入库 2 行、9 月 29 日入库 2 行；未出现日期交叉污染。冻结计划导出范围 `query` 返回 4 行，与列表总数一致。

## 验证与边界

- `php -l` 通过；新增 `statistics-period-filter-contract.php` 覆盖控件条件、`between`、单边界、交集、异常日期谓词，全部通过；原统计聚合、平台库存权限和门店库存权限契约测试均通过；`git diff --check` 通过。
- 浏览器工具仍报 `Unable to load browser request-header policy`，未能在 18091 可视页面核验。后端真实本地统一查询已按页面实际 payload 验证，无远程数据库操作。
- 前端源码、数据库迁移、工程脚本、生成物：无变更。后端源码：`后端代码/app/services/product/inventory/query/InventoryStatisticsUnifiedQueryProvider.php` SHA-256 `b4e01d56057d6f2960d5e53cee3a7d0b1ebaebb7094aafe073c188c84bbeefb1`。自动化测试：`tests/inventory/php/statistics-period-filter-contract.php` SHA-256 `b7752b0e18905f347f836015a4378c9b3acbd13c0262004a9eede7911a410e25`。本文件为验收证据。源码补丁 SHA-256 `57fbafd507dd7d9dfb423daeb66216bd04929b91e10e626131acc26173013ba1`。

状态：开发与本地自测完成，待产品经理对该固定修复确认验收；未 commit、未部署、未 push。
