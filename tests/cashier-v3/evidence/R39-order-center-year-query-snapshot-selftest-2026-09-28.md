# R39 订单中心全年查询快照读取修复 · 本地自测

- 基线 HEAD：`42ab217d8773f70e7041825b99863816e0c015a5`。
- 范围：仅订单中心销售单的权益服务快照只读映射；未改前端、结账写入、作废、手艺人/销售人修改命令。
- 根因：同一权益服务事实存在标准结账 camelCase、早期仅身份 camelCase、直通结算 snake_case 三种已落库格式；旧查询误把后两者交给销售明细快照解码器。
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php`，SHA-256 `effae346df4597e6dcbbc9edc17e42eda343ce2a0ee24bb92f859abab7da29d6`。新增注释说明服务快照与销售快照的边界、只读历史兼容和不可推断缺失值。
- 自动化测试：`tests/cashier-v3/php/order-center-service-snapshot-read-contract.php`，SHA-256 `b36e1efa8199ee0ab366f87f07d4ada81695e93432b7d593c1f335693f322d42`；`tests/cashier-v3/php/order-center-year-query-mysql.php`，SHA-256 `ffa599232472c2305a0a860ea151ba11eee64e8bdb11862a4212c7f3fddcfba2`。测试文件头部注明只读范围。
- 前端源码、数据库迁移、工程脚本、生成物：无。

## 数据架构自检

权威源为已结账的销售单与权益服务事实不可变快照；最小粒度仍为销售明细和服务事实，未增加业务事实。生效事件、业务/发生/结算/记录时间、事务、并发、幂等、Outbox、作废/退款冲销链均未修改。旧快照只用于历史人员展示；有有效劳动业绩事实时仍以事实为先，早期快照缺少的权重和业绩不推断。门店权限仍由原查询条件与后端 DataScope 强制执行；没有新增数据源、索引或报表计算。全部检查只读本地数据库，无数据迁移或回填。异常格式仍抛出可追踪错误，不静默清空历史人员。

## 自测结果

- `php tests/cashier-v3/php/order-center-service-snapshot-read-contract.php`：PASS，覆盖三种格式、事实优先和缺字段拒绝。
- `docker exec -i -e BACKEND_ROOT=/var/www/html mohe-cashier-app php < tests/cashier-v3/php/order-center-year-query-mysql.php`：PASS，本地 2026 全年正常单查询，13 个有权益服务记录的门店，逐页读出 16,471 条记录。
- `docker exec -i -e BACKEND_ROOT=/var/www/html mohe-cashier-app php < tests/cashier-v3/php/r37-entitlement-sales-query-mysql.php`：PASS，服务分组、纯权益、分页、来源与金额不串用。
- PHP 语法检查与 `git diff --check`：PASS。当前本机 PHP 会报告原构造函数的隐式 nullable 弃用提示，不影响语法及上述测试。
- 既有 `sales-order-craftsmen-snapshot-contract.php` 的共享 normalizer 静态文本断言失败；被断言的类未在本闭环修改。既有 `order-center-sales-readonly.php` 还包含与本次无关的退款状态旧断言，并在未引入 ThinkPHP Log facade 的孤立环境终止。未把两项记为通过，也未顺手修改其旧契约。

状态：开发与自测完成，待产品经理对上述固定文件版本确认验收；未 commit、部署或 push。
