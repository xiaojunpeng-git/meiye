# R33 市场报表列名取值来源优化——瑞昊部署记录

## 部署范围

- 固定源码提交：`e4a83ef6`（`R33 市场报表列名取值来源优化闭环`）。
- 目标仅为瑞昊正式实例 `rh.cc3798.com`，站点目录 `/www/wwwroot/rh.cc3798.com`，数据库实例为 `ruihao`。
- 本次只替换后端文件 `app/services/report/StoreUnifiedReportPhaseTwoServices.php`；未执行 SQL、未修改配置或历史业务数据，未部署其他客户实例。
- 市场业绩表与市场明细表共用统一报表返回契约；本次只补充和细化列名取值来源，不改变查询、合计、下钻、导出或数据权限口径。

## 部署前检查与回滚点

- 部署前远端文件 SHA-256：`4f3c34b712f600c3d603f90a5f3cc506eec987527b435c09f767d36ddfddf4f6`。
- 独立备份目录：`/www/backups/rh.cc3798.com/20260924-r33-e4a83ef6-market-source-explanations`。
- 回滚文件：`StoreUnifiedReportPhaseTwoServices.php.before`，SHA-256 与部署前远端文件一致。
- 本次无数据库迁移；回滚仅需恢复上述 PHP 文件并重启瑞昊 Swoole。

## 本地自测

- PHP 语法检查通过。
- `market-detail-summary-row-contract.php`：8/8 通过。
- `market-performance-service-visit-contract.php`：16/16 通过。
- `market-member-daily-aggregation-contract.php`：通过；仅出现既有依赖弃用提示。
- `market-detail-fixed-table-contract.php`：5/5 通过。
- `market-detail-member-columns-contract.php`：6/6 通过。
- `market-effective-people-drilldown-contract.php`：5/5 通过。
- 显式合同断言合计 35/35 通过，`git diff --check` 通过。
- 本次修改的报表服务与合同测试均补充或更新了业务职责、统计边界和兼容约束注释。

## 部署结果

- 部署后本地、上传暂存文件及远端正式文件 SHA-256 均为 `dade2bc496dbb13803b219e49d3d14804d26fd9f66b1d000689e21f97306a25b`。
- 远端 PHP 语法检查通过，运行缓存已清理，`/etc/init.d/ruihao_swoole` 已重启。
- 部署后 Swoole manager PID 为 `2150`，manager 数量为 `1`。
- 部署完成后检查近 30 分钟运行日志，未发现 `Fatal error`、`Parse error` 或 `Uncaught`。

## 线上验证

- 在瑞昊 8080 预览入口刷新后验证市场业绩表：分公司、门店、各来源人次/有效人员/金额、B 来源进店、各收款方式和总业绩均显示具体业务口径，不再显示通用占位说明。
- 验证市场明细表：日期、门店、会员/游客、手机、来源、进店、人次、有效人员、金额、登记/审核/制单字段均显示具体取值来源及限制说明。
- 验证期间仅执行报表查询与弹窗查看，未编辑业务数据。
