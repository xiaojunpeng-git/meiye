# R42 原销售收款来源遗漏导致成交人数错日

## 固定范围

- 基线 HEAD：`3b76ef92d6afc476a16daf59aa28c127aec6ef13`；共享工作区其余未提交改动不属于本轮。
- 后端：`app/services/report/StoreDealPeopleQualificationServices.php`，SHA-256 `2c80ec39ec7eaa412212a538be1541476d50c17d8677b430590c215ea01c4df2`。
- 测试：`tests/report-phase-four/php/r42-deal-people-snapshot-local.php`，SHA-256 `0e1abd2dd67f1156aa15da0ff55e89773afa9e74b6eb3503cbc93b59a8b54249`。
- 测试：`tests/report-group-dashboard/php/source-deal-people-local.php`，SHA-256 `33c377cd13257a0b1f0c584067407c80247b617a852cde665bf27e5d529a702e`。
- 无前端源码、数据库迁移或生成物改动；尚未 commit、push 或部署。

## 原因与口径

- 本地瑞昊只读样本 `XS26100400111` 的原单现金 200000 分，业务日为 2026-10-04，付款事实来源为 `cashier_snapshot`；2026-10-13 的 `BJ26101300015` 再补交 200000 分。
- 共用成交人数服务原来只读取 `sales_order` 类型的原单现金事实，因此漏掉 10 月 4 日已达标的原单，把 10 月 13 日补交误判为首次达标。
- 修复后原单有效现金同时识别 `sales_order` 与 `cashier_snapshot`，继续排除退款、其他收款、旧卡录入及不合格历史补交。原单只计一次，人数归首次达标业务日及原销售门店；补交现金仍归实际收款日。
- 10 月 13 日该门店 A 来源仍有另一会员的独立销售单达标，因此汇总人数可以保持 1；吴妍本人不再被重复计入。

## 自测

- PHP 语法：上述 3 个 PHP 文件均通过 `php -l`；相关已跟踪文件 `git diff --check` 通过。
- `r42-deal-people-snapshot-local.php`：PASS。核对原单/补交事实、10 月 4 日和 13 日资格事件、市场明细人数及补交金额、市场汇总与集团看板来源人数。
- `r41-deal-people-order-qualification-contract.php`：PASS。
- `r41-deal-people-cross-store-local.php`：PASS，事务回滚。
- `r41-deal-people-partner-local.php`：PASS，事务回滚；含合作方列表、明细和导出。
- `source-deal-people-local.php`：PASS。同步修正了该既有测试中过时的固定门槛文案断言，改为当前平台可配置门槛说明。

本轮只读排查与本地测试未修改生产数据；待产品经理对上述固定文件版本确认验收。
