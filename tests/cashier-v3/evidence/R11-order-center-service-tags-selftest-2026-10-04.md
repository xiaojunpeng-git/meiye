# R11 订单列表服务标签自测

- 基线 HEAD：`2e07ae86808046e6fc4ad4571442de71ccbde5c8`
- 范围：订单中心销售订单的项目品名右侧显示发生时的“体验”及服务客数标签。没有修改金额、人员、订单状态、结账或服务人次算法。
- 权威来源：购买项目读取已结算销售订单行的 `is_experience`、`service_object`、`friend_counts_as_customer`；权益服务读取已完成服务事实中的相同快照。旧订单只有在可关联 V3 正式行时显示这些标签，不用当前商品或岗位设置补推。
- 显示规则：体验标记显示“体验”；朋友且计数显示“朋友算”；本人或朋友不计数显示“不算人数人次”；本人且计数不显示客数标签。非项目行不显示这些标签。
- 本地只读实数：订单 `XS26100400019` 的“舒缓灸”正式销售行 `is_experience=1`，映射结果为 `isExperience=true`、`serviceRecipientType=self`、`friendCountsAsCustomer=true`，因此只显示“体验”。
- 自测：`order-center-sales-service-lines-contract.mjs` 通过，覆盖四种本人/朋友计数状态及非项目隔离；门店端 Vite 构建通过；PHP 7.4 语法检查和 `git diff --check` 通过。
- 验证边界：旧的 `order-center-sales-readonly-contract.mjs` 停在共享查询工具栏 `selectScope` 的静态断言，未到达本次标签检查；浏览器自动连接不可用，未取得页面截图，页面呈现待产品经理验收。

| 分类 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `9320cf93099afd7e6caa9dd2e2fd225682493722f3cd3e286c183a3552a44a5a` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php` | `d235febe6e5a4c011862876de973b44127749179a9b83a9cec1a65d45c4573b3` |
| 自动化测试 | `tests/cashier-v3/js/order-center-sales-service-lines-contract.mjs` | `0d9bfb8acfc5758a49a319013bca0fb269ca8199c63cada44cf4d9b50b4208d4` |

数据库迁移、工程脚本、纳入交付的生成物：无。本次更新了 `CashierV3SalesOrderQueryServices.php` 的快照来源注释，并新增 `OrderCenterView.vue` 标签条件注释。

状态：开发和自测完成，待产品经理确认固定版本；未 commit、部署或 push。
