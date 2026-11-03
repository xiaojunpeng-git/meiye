# 第44轮：会员端订单统一第三方支付记账

本目录验证手机端新单的记账分类。实现及自测均在唯一源码正本和本地 `ruihao` 数据库完成，未连接支付供应商，未执行真实支付、退款、远程迁移、提交或发布。

## 本轮业务边界

- 会员端普通购买、拼团及其他沿用 `store_order` 的活动订单，在**创建时**冻结 `accounting_method=third_party`；会员商城新购买、充值同样冻结。
- 订单中心列表、详情、导出读取同一记账名称“第三方支付”。外部实际到账使用该收款事实代码；真实渠道、供应商、商户号及交易号继续保留原支付证据。
- 余额扣款仍是 `member_balance` 和原余额事实，仅记账名称统一；不增加外部收款或现金业绩。零元单不生成虚假收款行。
- 迁移不给历史或已创建待付订单回填。升级前待付单在升级后支付，继续沿用创建时的原口径。
- 此分类是会员订单的自动记账分类，不加入人工收银、补交或记账方式纠错的可选项。最终锁定付款时仍校验会员身份。
- `store_order` 的兼容展示只读原单付款，不额外创建销售、现金或权益事实；不能把记账展示当作新增的业务操作授权。
- 原单财务报表的统一收款 Reader 同样读取创建标记归入第三方支付。单笔/组合原收款的资格、金额及排除余额规则继续复用原权威查询；汇总、下钻和导出使用同一收款集合。

## 文件分组

后端源码包含创建标记、共享记账投影、记账字典与事实/结算白名单，以及人工入口保护，完整路径及本次交接时的 SHA-256 见 `delivery.json`。

数据库迁移：`后端代码/database/upgrades/2026-11-02-r44-third-party-accounting/`。本地已执行并回读确认两张订单表的空默认标记列与一条启用的 `third_party` 字典；无历史数据回填。

自动化测试：`local-rollback.php`、`contracts.php`、`legacy-readers.php`、`manual-entry-rejection.php`。本支付分类闭环没有前端源码或工程脚本改动。

另按第44轮主任务安排完成当前正本 `cashier-v3` 本地生产构建，未部署。18091 的容器挂载与本地构建源一致，`prebuild` 原有快照回归 70 项通过，Vite 构建通过；210 个源码及共享依赖文件构建期间未变。标准 `前端代码/cashier-v3/dist/` 属于生成物，不能作为修改入口或混入支付分类源码清单；入口、源码树及产物指纹见 `cashier-build.json`，日志 `/tmp/r44-cashier-build.log`。

`baseline.json` 记录接手时的 HEAD 和修改前文件指纹；工作区已有其他任务改动，整个文件的 Git diff 不能全部视作本轮变更。`CashierV3CheckoutPreparationServices.php` 在开始记录的文件集合之外，交接清单记录其当前指纹，不补造修改前证据。平台/门店及会员端既有代码由各原任务继续维护，本闭环仅列支付分类改动。

期间其他任务将 HEAD 从 `4d35b334` 更新到 `5db3dd02`；本支付分类涉及的 18 个后端文件在这两个提交间无已提交差异，已记录 `baseline-drift.json`，没有重置或覆盖既有未提交改动。

## 已完成验证

2026-11-03 本地运行：

- `local-rollback.php`：55 项通过。真实会员下单、余额支付、混合支付、受控模拟在线回调、充值、零元单；旧待付回调不重分类；渠道/金额/交易号不符拒绝；重放不重复入账；共享详情和导出名称一致；测试事务完整回滚。
- `contracts.php`：23 项通过。最终收款计划、整元校验、固定字典保护、成团及其他活动投影、余额资金性质、退款冲销、现金/实际业绩/退款金额、组织数据范围与导出一致性；测试事实完整回滚。
- `legacy-readers.php`：13 项通过。原单及组合收款分类、历史微信不变、余额/待付/退款排除、组织范围、业务日、真实财务报表矩阵/下钻/完整导出一致；测试原单与组合行完整回滚。
- `manual-entry-rejection.php`：新增 14 项通过。直接调用真实准备、锁定最终提交、充值服务，人工 `cashier` 身份伪造 `third_party` 均精确拒绝；每次前后资金事实、销售订单、收款、充值、事件数量与会员余额一致。最终提交读取真实已持久化草稿；拒绝后草稿原样保留；测试结束将唯一准备夹具回滚清除。未替换校验器、仓储或写入器，未触发支付供应商。证据见 `manual-entry-rejection-evidence.json`。
- 累计 105 项测试通过；新增防伪造用例未重复运行原 91 项。18 个触及后端 PHP 文件已有语法检查通过，补测未修改业务代码。

这些是本地领域与报表验证，不是支付供应商联调或产品经理验收。正式发布前仍须按本轮总流程完成平台、门店、会员端页面验收及目标实例迁移核对。

## 运行

从 `美容源码/` 执行；必须覆盖镜像的默认入口，避免启动第二个应用进程：

```sh
docker run --rm --network mohe-net --volumes-from mohe-app --entrypoint php -v "$PWD/tests:/tests:ro" mohe-app:local /tests/r44-third-party-accounting/local-rollback.php
docker run --rm --network mohe-net --volumes-from mohe-app --entrypoint php -v "$PWD/tests:/tests:ro" mohe-app:local /tests/r44-third-party-accounting/contracts.php
docker run --rm --network mohe-net --volumes-from mohe-app --entrypoint php -v "$PWD/tests:/tests:ro" mohe-app:local /tests/r44-third-party-accounting/legacy-readers.php
docker run --rm --network mohe-net --volumes-from mohe-app --entrypoint php -v "$PWD/tests:/tests:ro" mohe-app:local /tests/r44-third-party-accounting/manual-entry-rejection.php
```

测试限定本地 `mysql/ruihao`，不修改业务全局开关。真实下单测试使用本地会员 913081、门店 118、商品 625093 和零价项目 625196；夹具变化会明确失败，不能将失败当成跳过通过。

## 注释与保留项

本次在创建冻结、外部收款与余额区分、原单投影、固定字典、防人工伪造及迁移回滚边界增加业务注释。既有支付回调校验、幂等、余额本金/赠送金、权益履约和报表口径继续使用原实现，未复制第二套结算逻辑。
