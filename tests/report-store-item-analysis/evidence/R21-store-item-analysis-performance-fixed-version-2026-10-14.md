# R21 门店品项分析提速与导出分组表头：本地固定版本

## 范围与版本

- 基线 HEAD：`60f9755289ae5e0b9cf6afd8f5f6b98073b21db6`。本轮尚未提交、部署或推送，等待产品经理对本固定版本确认验收。
- 前端源码：`前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`。平台 `18081` 的报表 iframe 使用该 Vue 3 页面；本轮仅让门店品项分析的 CSV 导出使用服务端 `column_groups` 生成与页面一致的两层表头，不改页面表格及金额。CSV 可用 Excel 打开，但本轮不改为 XLSX 文件。
- 后端源码：`app/services/report/StoreUnifiedReportServices.php`、`app/services/report/StoreUnifiedReportOrganizationDimensionServices.php`、`app/services/query/metric/RegisteredMetricReadServices.php`。
- 自动化测试：`tests/report-store-item-analysis/php/dimension-snapshot-performance-readonly.php`、`tests/report-store-item-analysis/js/export-grouped-headers.mjs`。
- 数据库迁移、工程脚本：无。生成物：本地 Vite `dist/` 构建用于编译检查，不是 8080 发布包，也不纳入源码提交。平台端 `18081` 仍沿用当前 `前端代码/admin/` 入口。
- 文件 SHA-256：
  - `StoreBusinessReportView.vue`：`5b072dc2f4f541033190731ed659559f1d51daa467ae3a1978b24e321fa270bb`
  - `StoreUnifiedReportServices.php`：`aa9f479b785c94cfc010b925982250afcdecb29135202fde437d999601289636`
  - `StoreUnifiedReportOrganizationDimensionServices.php`：`8d9125e120dce9d41888864d18ec741b13a4edfe28031413ba7dcd43c0fcad4e`
  - `RegisteredMetricReadServices.php`：`6496d5561408335aefda61341bae18a0e31b89708e4635c7d8e0d70a286e6564`
  - `dimension-snapshot-performance-readonly.php`：`7b443752e3f0d32b9ad1b6e5b90de98af9670ad7aeab1595efb18cd79eedc7b1`
  - `export-grouped-headers.mjs`：`a6bae07ba69616668c1f75cbd7076dc815c80bdfe22f40dfd210c75c71475f8b`
- 三个后端文件相对基线 HEAD 的工作区 diff SHA-256：`c76959997479692d25c39ee40114949fff8ee7dd0ce49d27c06f410d9777b1dd`；此指纹包含开工前已有的其他任务改动，仅用于锁定当前验收文件内容，不表示它们属于 R21。
- 三个后端文件以及 `StoreBusinessReportView.vue` 在本轮修改前已有其他任务的未提交改动；提交时只能精准暂存本轮增量，不得把既有改动纳入本轮提交。

## 数据架构自检

业务权威源与统一事实粒度不变：收款、退款、合作方分成和服务消耗仍由现有事实及注册指标 Reader 提供。成功时点、业务日期、事务、幂等、冲销、历史金额与分类归属均未修改。组织维度仅在单次只读查询内复用当前配置快照；下一次查询重新加载门店组织路径和配置，避免长生命周期进程持有旧映射。门店权限仍先由后端裁剪，查询、合计和导出使用相同服务。无回填、迁移、生产数据写入或跨实例操作；恢复方式为撤销本轮未提交的精准增量，保留原有工作区改动。

导出表头使用导出响应自身的列与分组元数据，不使用屏幕上可能已变化的分类列。首行放“现金业绩、分成业绩、分成后业绩、消耗业绩”和分类分组，次行放对应的“当日、累计”等子列；记录与合计行不重新计算。CSV 不支持真正的合并单元格，分组名只占该组首列，后续格留空。

## 本地证据

- 相同的本地 `ruihao` 跨门店范围（102 家有权限门店、102 行、82 列，2026-10-01 至 2026-10-14）：优化前约 `11.0s`、约 `6,029` 条 SQL，其中组织维度表约 `5,895` 次；优化后约 `5.4-5.5s`、约 `130` 条查询，其中组织维度 `12-13` 次。累计现金业绩前后均为 `2035541` 元。
- 新只读回归在本地数据库只读事务中通过：查询 `5.507s`，导出 `5.698s`，两者记录、列、合计和口径版本相同，组织维度查询各 `12` 次。
- 扩展后的只读回归再次通过：102 家门店、102 行；查询 `5.883s`，导出 `5.120s`，两者记录、列、`column_groups`、合计和口径版本相同，组织维度查询各 `12` 次。导出元数据包含“现金业绩、分成业绩、分成后业绩、消耗业绩”。
- 前端定向测试通过：CSV 两层表头分组及子列位置正确，动态分类列按导出响应排列，其他报表导出结构不变。`npm run build` 通过；`dist/` 不作为已完成的集成包。
- 单门店 `[23]` 权限范围只返回一行，查询与导出一致；2026-10-13 当日现金业绩仍为 `-333` 元。
- 三个修改的 PHP 类均通过容器 PHP 语法检查；`tests/report-store-item-analysis/php/contract.php` 通过；共享快照使用者 `tests/report-phase-seven/php/group-dashboard-optimization-local.php` 通过；`git diff --check` 通过。
- 现有 `tests/cashier-v3/js/store-business-report-contract.mjs` 为 `45` 项通过、`1` 项失败；失败项是大单报表既有源码字符串契约，与本轮导出表头无关，未计入通过结果。
- 两份旧的源码字符串契约 `store-operations-organization-dimension-contract.php`、`phase-two-organization-dimension-contract.php` 仍在未改动的既有段落报错，未计入通过结果。本轮没有为了通过字符串检查而更改其他报表。

## 剩余限制

本地剩余耗时主要在服务消耗事实的大范围查询（两条各约 `1.4s`，本地执行计划从服务表读取约 `7,485` 行）。本轮没有修改指标 SQL、索引、金额口径或生产环境；线上耗时与实际 Excel 打开效果仍需在授权发布后验证。
