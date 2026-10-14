# R28 空来源销售单更正固定版本（2026-10-14）

状态：本地开发与自测通过，待产品经理对本固定版本验收；未 commit、未部署、未 push。基线 HEAD：`486f8c7ab62a2ecf4b9bfad9d2a43671fc517ced`。闭环文件列表的 SHA-256 清单指纹：`52f8254c1d7cba331409b481cee3afb740ca0a8bd434f8f26d500c7c07e0fe88`。

## 问题与修复

- 订单 `XS26100300017` 的本地权威销售主单属于门店 100；来源一级/二级 ID 均为 0，三个来源名称快照均为空。订单列表将空来源显示为 `—`，旧前端却把它当作更正请求的 `expectedValue`，与服务端原值空字符串不符，被误判为并发修改。
- 销售列表新增 `sourceExpectedValue` 原值字段；展示用 `source` 仍显示 `—`。更正弹窗发送原值而非显示占位符。旧接口暂未提供原值时，仅在两个来源 ID 都为 0 且显示 `—` 时兼容还原为空值。
- 服务端原有的来源 ID、快照原值和订单锁定校验不变；实际已变更的来源仍被并发保护拦截。更正仍走已有主单、事实、审计和事件事务，不改变金额或业务时点口径。

## 文件清单与指纹

| 类型 | 文件 | SHA-256 |
|---|---|---|
| 前端源码 | `前端代码/cashier-v3/src/services/orderAttributionExpectedValue.js` | `e2034776e16dfaa4dcd4f8fdb61f349a7d7ef9f8c693bf8addf619ecc4c22b6b` |
| 前端源码 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `2359dbb86a4fd60b01e21cc8dab779521ed7a5e03ee75b1563b6a0be342e2e8a` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php` | `c96d71c962b4a3a7ea60bb71a6bc5f77dc6774f8e0b2e742d2426e4b4459e222` |
| 自动化测试 | `tests/cashier-v3/js/order-attribution-empty-source-contract.mjs` | `aa69ccb1ccb6b524ebba8f7f1321a4a375e80729c1715a06fe6185a2202bfe7d` |
| 自动化测试 | `tests/cashier-v3/php/order-attribution-empty-source-contract.php` | `63ff59abca2808bdef895034750b9bb50a88684f995fc4ffe8131987be3f4c8c` |
| 自动化测试 | `tests/cashier-v3/php/order-attribution-empty-source-local-smoke.php` | `d2d3a3a3f8efb15e35d2dccdba36ccc0f3a50a7ee84716740ebdfd5c629dfad2` |

数据库迁移、工程脚本：无。生成物：本地 Vite `dist/`，不进入源码提交；构建入口引用 `index-00c1e73a.js` 与 `index-8472f4de.css`。验收证据：本文件。业务注释已补充或更新于上述两处前端源码及后端源码，并在新测试文件中说明约束。

## 自测

1. `node tests/cashier-v3/js/order-attribution-empty-source-contract.mjs`：通过；覆盖原值为空、旧接口、正常来源与日期不变。
2. `php tests/cashier-v3/php/order-attribution-empty-source-contract.php`：通过；空来源展示/原值分离，一级/二级来源优先级不变。宿主机新 PHP 对现有 vendor 发出弃用提示，不影响断言。
3. `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/order-attribution-empty-source-local-smoke.php`：通过；对同号订单在本地事务内成功更正并生成审计，回滚后原来源仍为 0；以 `—` 伪造旧值依旧被拒绝。
4. 现有 `order-attribution-local-smoke.php` 默认回滚模式：10 项操作通过，`persisted=false`；现有前端桥接合同：6 个更正命令均到达适配器且未写库。
5. 门店端 `npm run build`：通过；`php -l` 与 `git diff --check`：通过。`http://127.0.0.1:18091/view_cashier_v3/` 返回 HTTP 200。

## 数据架构自检

- 权威源为 `cashier_v3_sales_order` 已结算主单的来源 ID 和快照；列表只读投影携带原值，显示占位符不入库。
- 更正仍在既有事务中锁定原单，按旧值和来源 ID 比较后更新主单及关联事实、写审计与事件；幂等键、权限、时间、冲销与历史记录路径不变。
- 无新事实粒度、无金额/业绩计算、无迁移/回填、无跨客户共享状态；性能只增加一个由已有字段生成的响应值。真实库测试全部回滚并核对原值恢复。

## 验收与发布边界

请在本地 18091 的订单中心查询 `XS26100300017`，打开“更正来源”并核对该弹窗不再因空来源立即报并发冲突。不要在生产/预览站执行真实更正作为验收操作。本固定版本未获产品经理确认前不提交；部署瑞昊或其他实例、线上验证与 push 均需后续单独明确授权。
