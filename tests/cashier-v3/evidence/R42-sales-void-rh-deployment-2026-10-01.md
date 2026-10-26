# R42 销售订单恢复原状后可作废：瑞昊发布记录

- 产品经理已确认固定版本验收，并明确要求汇总、commit、部署瑞昊及 push。本地业务提交：`06411766b722d405210925d3665430b6f9b1b2de`；从瑞昊既有发布父版 `95815ced02208c5913299da717de8c60605ad561` 形成等价独立发布提交 `bd697f52febbcd400db93078b579ebdf4b2058bb`，已推送 `origin/release/r42-sales-void-20261001`。逐一核对本次 4 个文件的 Git blob 与业务提交相同，未推送开发分支的其他历史。
- 唯一目标是瑞昊生产 `rh.cc3798.com`，后端 `/www/wwwroot/rh.cc3798.com`，数据库只读核实为 `ruihao`。升级记录最新为 `20260929-001-inventory-count-operator-type`，其后为盘点草稿升级和 `20260924-001-cashier-v3-checkout-reservation-service-link-v1`；本次无迁移、无配置修改、无生产业务数据写入。
- 只部署 `app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php`。线上旧文件 SHA-256 `23cc1b9809543d5c43a1647b9e134f9618fb0878cd7df834308de90d2a889584` 与业务提交父版一致；新文件 SHA-256 `224bbd24e6eee8bfe68be37beaf2a825663589d11bb2c0acbd3b728a08fec68c` 与验收版一致。无前端构建或静态包部署，其他客户实例未操作。
- 独立备份和回滚点：`/www/backups/rh.cc3798.com/20261001-r42-sales-void-06411766/before.php`，已校验旧指纹；同目录 `stage.php` 在替换前通过 PHP 7.4 语法及新指纹校验。回滚时仅需恢复备份的 PHP 文件并重启瑞昊单实例 Swoole；不涉及数据库回滚。服务器原文件为 `www:www 644`，新文件保持相同权限。
- 原子替换后重启 `/etc/init.d/ruihao_swoole` 成功，`manager_count=1`，`20800` 端口监听；线上 PHP 7.4 语法检查通过，运行文件与新指纹相同。正式和 preview-8080 门店端入口均返回 HTTP 200；最近运行日志未检出新增 Fatal、Parse 或 Uncaught。两项本地定向合同测试再次通过。
- 线上验证只包括运行文件、进程、入口及只读迁移状态；没有为了测试去作废真实销售单。逐笔恢复与简短提示的业务路径由本地定向测试和发布前瑞昊真实事实只读对账覆盖，不把线上写路径声称为已实测。宽范围订单生命周期合同原有两项与本闭环无关的静态断言仍不记为通过。
- 分组：前端源码、数据库迁移、工程脚本、生成物均无；后端源码 1 个、自动化测试 2 个、自测证据 1 份，精确文件与哈希见 `R42-sales-void-restored-state-selftest-2026-10-01.md`。本文件是部署证据，不属于已验收业务提交。
