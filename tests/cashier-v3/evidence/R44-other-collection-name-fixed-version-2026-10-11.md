# R44 其他收款名称纠正：本地自测与固定版本

- 基线 HEAD：`b3628f801e9b8c64f8a59ea31a6e804f6b7552f5`。只改统一源码正本和本地 `ruihao` 测试库；未连接远程实例，未 commit、部署或 push。其他脏工作区文件未改动。
- 复现：消费订单 `XS26101100008` 的结账草稿和正式收款代码均为 `other_collection`，收款事实金额为 12000 分；本地配置将该代码的默认名、显示名误设为“现金”，导致已结算名称快照为“现金”。本地共有 28 笔相同代码与名称组合，其中 9 笔在独立 `cash` 启用后发生。旧配置无管理员改名审计。
- 修复：订单中心从权威代码读出“其他收款（原记录：现金）”，保留原名称以便追溯；正常 `cash`、正常 `other_collection` 和自定义名称不变。旧收款的代码、金额、名称快照和事实均未修改。后续名称通过受控数据修正，将本地配置的默认名、显示名由“现金”改为“其他收款”。
- 本地配置执行：`02-执行.php --database=ruihao` 只读预检 `eligible=true`；带 `--execute --code-version=R44-local-unaccepted` 后，仅配置 id 7 从版本 1 更新为 2，`cash` 配置保持版本 1。配置审计键 `R44-OTHER-COLLECTION-SEED-NAME-REPAIR`，升级键 `20261011-001-other-collection-name-repair`。执行后预检 `eligible=false`，28 笔历史名称快照仍为“现金”；目标订单的 `payment_fact` 仍为 `other_collection` / 12000 分。
- 页面验证：本地 18091 订单中心重新查询后，目标订单显示“其他收款（原记录：现金） ¥120”；另一笔真正现金订单继续显示“现金 ¥3,960”。未创建新的真实收款交易。
- 测试：PHP 语法检查通过；`r44-other-collection-name-contract.php`、`order-center-settlement-integrity-contract.php`、`order-source-snapshot-contract.php` 均通过；`git diff --check` 通过。宿主机 PHP 对本文件既有可空参数发出弃用提示，不影响测试断言。

## 数据架构自检

- 权威源是已结算收款明细及 `payment_collected` 事实的 `payment_method` 代码；名称快照只记录结账时配置。事实粒度仍为一笔成功收款，不新增交易事件、聚合或金额计算。
- 成功时点和四类时间、幂等键、结账事务、退款/作废冲销均未改变；配置修正以版本条件更新，并在同一事务写配置审计和升级日志。既有历史快照不覆盖，列表为可重算读投影。
- 人员、门店、会员权限沿用订单中心后端查询；没有新增筛选或跨实例访问。只对已取回的收款行做常数时间名称判断，无额外 SQL、索引或性能变化。
- 对账：目标正式收款代码与 `payment_collected` 事实均为 `other_collection`、12000 分；配置从版本 1 到版本 2，历史快照计数仍为 28。执行脚本对配置状态和目标数据库做前置校验；其他实例的配置不自动变更。

## 文件与指纹

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php` | `cfc0c50c56e62311804ad0337c0f138e2ecaa3a64b945aa9e117e615d3bb2e8a` |
| 数据修正 | `后端代码/database/upgrades/2026-10-11-R44-其他收款名称纠正/02-执行.php` | `272c087baf106d85878368dc8bbad51cd19035b809228d026dc38ebdd3afa9b8` |
| 数据修正 | `后端代码/database/upgrades/2026-10-11-R44-其他收款名称纠正/00-执行说明.md` | `bdc0e064d63c3d1f575106c442764a42e1217b368a3ce0659f7d84e87309a856` |
| 自动化测试 | `tests/cashier-v3/php/r44-other-collection-name-contract.php` | `ab04b29e7a65db840748cc0e905e9637304c3afadaca66700f36b89533b49bd3` |

- 已跟踪源码补丁指纹（`git diff --binary` SHA-256）：`9d03fca30ba35c62e84d865511efb86e128057325c75dda574008994b5c5574b`。新增文件以各自 SHA-256 固定；本记录是验收证据，非源码或生成物。
- 注释更新：订单查询的旧快照双重标注分支、数据修正脚本的前置条件与不可改历史边界、回归测试的职责说明。前端源码、工程脚本、生成物均无本轮变更。
- 当前状态：开发与本地自测完成，待产品经理对上述固定范围确认验收；尚未取得 commit、生产数据修正、部署或 push 授权。
