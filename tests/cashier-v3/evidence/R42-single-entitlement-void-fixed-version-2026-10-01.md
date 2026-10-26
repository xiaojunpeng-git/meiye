# R42 单条纯权益消费可整单作废：本地固定版本与自测

- 基线 HEAD：`ccac4cef3c69f4117ef5928687156dcdaa585333`。
- 范围：订单中心纯权益消费单（含仅 1 条服务）作废身份传递；不更改销售订单资金作废、不允许外部逐条服务冲销。
- 8 个源码／测试文件的有序 SHA-256 清单指纹：`e1e47663aa83476595bf8583aa39ddc9825a1b593e6fe0bca4e57acd146debfb`。该指纹不包含本证据文件。
- 状态：开发和本地针对性自测完成；待产品经理对本固定版本确认验收。未 commit、未部署瑞昊、未 push；未在线上点击“确认作废”。

## 闭环文件与注释

| 分组 | 文件 | SHA-256 | 注释说明 |
|---|---|---|---|
| 前端源码 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `7aa3aadd79da467605c898d4066e2a8e86e9440a268ee24212a930f34d020ea0` | 说明单条权益仍以结账组提交一次整单冲销。 |
| 前端源码 | `前端代码/cashier-v3/src/services/cashierV3OrderProjectionContract.js` | `aded66a08a3e178374274563a524da22ad1f4bdc4a292174b0f3c04586abb6bb` | 说明显式组身份、旧投影回退和冲突拒绝边界。 |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php` | `5c3380f47edced2ef7b9ca4d817c28fcde587726143cfd03c203c97eeb51ca91` | 说明纯权益列表／详情为何返回权威结账组标识。 |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php` | `a12bcdd2d7c3d716754e7f443f85799520d22776587ee9e7b622e0f91b0affe9` | 说明从规范服务订单 ID 恢复组身份及双身份冲突拒绝。 |
| 自动化测试 | `tests/cashier-v3/js/order-void-receipt-recovery.mjs` | `ea429a688dd2b506133f74db3af30610b6df8cd724fb33709b2da59e66d78d97` | 覆盖单条权益、旧投影、冲突、非法 ID 及同键重试。 |
| 自动化测试 | `tests/cashier-v3/php/r37-entitlement-sales-query-mysql.php` | `95c460a99db2afb2eb19277aec498f3f3cb571c39f9aec0c298033d6c51b508a` | 列表与详情均核对组身份。 |
| 自动化测试 | `tests/cashier-v3/php/r38-service-void-rule-mysql.php` | `ccc8dfe1b9dcec301971947c50d57c6e077cbf7fd9eb9cd4a3ccc17ec1ff2f7c` | 本地事务回滚覆盖只有一条服务的作废、返还、幂等及业绩冲销。 |
| 自动化测试 | `tests/cashier-v3/php/pure-entitlement-void-policy.php` | `62a404202d8cd1a9bd50f4ec20c24a4ea1b704dcfe1ea4165bcfdf80f4148a66` | 覆盖组 ID／订单 ID 规范化及单条服务 ID 拒绝。 |

数据库迁移：无。工程脚本：无。生成物：本地 `cashier-v3/dist/`（忽略，不作源码或提交）。验收证据：本文件。

## 数据架构自检

- 权威业务源：`cashier_v3_entitlement_service_fact.checkout_request_id`；纯权益组在同一结账请求下按门店和租户读取。列表只是展示投影，不决定作用集合。
- 事实粒度：服务完成事实每项一条；作废操作与权益返还、业绩冲销均按服务事实追加反向事实。只有 1 条服务的组依旧是一笔完整消费。
- 生效时点：原核销事实保留原业务日期和真实时间；作废成功后的反向事实使用实际作废时间，不伪造原时间。
- 事务／并发／幂等：未改写写入链路；既有整组事务锁定来源并按稳定顺序处理，失败整体回滚，命令同键重试不重复返还。
- 作废／恢复：仅纯权益、无销售资金单可走该入口；单条 `serviceFactId` 的外部请求仍拒绝，防止遗漏同组其他业务；已作废项不重复退回。
- 维度／权限：后端继续按租户、当前门店和操作权限强制校验；浏览器传入双身份不一致时在事务前拒绝。
- 报表／接口：不新增口径，既有服务、权益和业绩反向事实继续被统一 Reader 消费；列表和详情增加同一权威组标识供写命令绑定。
- 索引／性能／历史：复用现有结账请求索引；只增加投影字段，无迁移或历史回填；旧投影仅允许从规范 `service:<requestId>` 读取。
- 对账：本地回滚测试核对作废操作、返还次数、权益规则余额、正反业绩净额和重放；退出后夹具清零、原余额不变。

## 验证

- `node tests/cashier-v3/js/order-void-receipt-recovery.mjs`：通过。
- Docker PHP 7.4 `pure-entitlement-void-policy.php`：通过。
- Docker PHP 7.4 `r37-entitlement-sales-query-mysql.php`：通过，列表／详情组 ID 一致；只读 `EXPLAIN` 已执行。
- Docker PHP 7.4 `r38-service-void-rule-mysql.php`：混合、两条纯权益和单条纯权益均通过；全部事务回滚。
- `php tests/cashier-v3/php/cashier-draft-command-projection-contract.php`：通过。
- PHP 语法检查、`git diff --check`：通过。
- `npm run build`：通过，产物 `index-4c99f306.js`；现有大包提示不属于本闭环。
- 扩展旧静态测试 `order-lifecycle-contract.php`、`order-lifecycle-frontend-contract.mjs`、`order-center-sales-readonly-contract.mjs` 在与本改动无关的旧字面量断言处失败；`r37-sales-query-column-parity.mjs` 固定比较旧 SHA 的整个函数文本，因本次获授权修改 `submitServiceVoid` 而失败，不能作为本闭环回归结论。未顺手改写这些冻结测试或其他任务文件。

瑞昊线上 `FW100100287` 的原始失败请求未留有可重放的请求体；因此不能声称已在该账号真实线上作废成功。该单仍需产品经理验收固定版本后、另行授权部署及线上验证。既有脏工作区均保持原状，提交时只可精准暂存上表文件及本证据。
