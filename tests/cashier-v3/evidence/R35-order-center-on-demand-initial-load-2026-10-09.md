# R35 订单中心按页签当天加载自测

- 基线 HEAD：`b9547566d0a35db9fdff83e8c84fa77eefbc3fa5`
- 范围：登录根状态只返回订单中心八个页签的入口和未加载元数据，不查询无日期的消费订单；点击左侧“订单”默认只查询消费订单当天数据，切换页签才查询该页签当天数据。报表下钻保留其指定日期。
- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue`（更新初始化职责注释，查询时机沿用既有实现）。
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php`、`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterPartitionProvider.php`。
- 自动化测试：`tests/cashier-v3/php/order-center-sales-readonly.php`、`tests/cashier-v3/js/order-center-sales-readonly-contract.mjs`。
- 数据库迁移、工程脚本、生成物：无。

## 数据架构自检

- 权威源与粒度：销售订单仍由既有授权列表查询读取；根状态仅包含入口元数据，不形成业务事实。其他七类单据仍由各自既有查询读取。
- 业务时间：默认筛选继续使用页面既有的当天 `business_date` 范围；报表下钻的指定日期不变。操作时间、结算时间和历史快照未修改。
- 写入与统计：没有业务写入、事务、幂等键、Outbox、冲销或指标计算变化；退款与作废状态仍由原查询权限和状态规则处理。
- 权限与性能：根快照不再进行无日期销售列表读取；按页签查询仍经后端原有数据权限、分页与状态过滤。没有新增索引或跨店读取。
- 历史兼容与对账：既有记录未修改；根状态的 `not_loaded` 与真实查询的零记录有别。可通过根分区零记录、当前页签当天结果及非当前页签未请求三项验证。

## 自测结果

- `php tests/cashier-v3/php/order-center-sales-readonly.php`：50 通过，0 失败；PHP 当前版本对既有 nullable 声明有 deprecation 警告。
- `node tests/cashier-v3/js/order-center-sales-readonly-contract.mjs`：17 通过，0 失败。
- `php tests/cashier-v3/php/workbench-root-production-contract.php`：10 通过，0 失败。
- 两个后端改动文件 `php -l` 通过；`git diff --check` 通过。
- `node tests/cashier-v3/js/bridge-contract.mjs`：35 通过，30 失败。其旧根字段/权限夹具与当前契约不一致；本次未修改 bridge 源码或该测试，不能据此宣称桥接整套件通过。
- 未进行带登录态的浏览器网络抓包或产品经理验收；不以静态契约测试代替真实页面验收。

## 固定文件指纹

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterPartitionProvider.php` | `4c447a1017a74c258d7d1d4a78711f263460b00c847a706f96274c89c74d3d5b` |
| `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php` | `79b2ff1de4ecc3039061ec3177cd8cd7e88b7b09da279d53d91b122f87df2e65` |
| `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `89291cc1d5b3426d0ab7757ce15c89284f823d374b90f44d42cb3598eafdba54` |
| `tests/cashier-v3/php/order-center-sales-readonly.php` | `d79d84b7cad65c990d13bccf867872b4f0e0276b8b6df344251833217d608a9d` |
| `tests/cashier-v3/js/order-center-sales-readonly-contract.mjs` | `f277b991e5ef47f0ee595db98112ec483551282d80afc2c8423087b90320911d` |

- 以上五文件补丁指纹：`4f87ca9da409c2fb2ee3a20011b2a041f7da9c486ce1f4ead157649fcd796317`。
- 本次更新的业务注释：`CashierV3OrderCenterPartitionProvider.php`、`CashierV3SalesOrderQueryServices.php`、`OrderCenterView.vue`。
- 状态：开发与自测完成，待产品经理对上述版本确认验收；未 commit、未部署、未 push。
