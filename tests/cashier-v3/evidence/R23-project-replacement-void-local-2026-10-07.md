# R23 项目替换作废：本地技术验证

- 基线 HEAD：`68f68441817801b103dbde76636c3d3fb3571e4f`
- 固定文件指纹（下列 11 个文件 SHA-256 清单的 SHA-256）：`4fb81465ddd728f58fcdef2cceabedd968845657e475201521f0e79db961cb11`
- 范围：只允许订单中心 V3 已完成项目替换作废；卡升级、项目升级及旧版记录不开放。无收付款或退款；原操作保留，新增作废审计及反向事件。

## 文件分组

- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue`、`前端代码/cashier-v3/src/components/order/BusinessRecordDetailOverlay.vue`、`前端代码/cashier-v3/src/services/cashierV3ActionManifest.js`、`前端代码/cashier-v3/src/services/cashierV3Bridge.js`
- 后端源码：`后端代码/app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php`、`后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php`、`后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php`、`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php`、`后端代码/app/services/cashier/v3/order/CashierV3OrderCenterVoidServices.php`、`后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php`
- 数据库迁移：无；复用既有订单中心作废审计表与权益版本表。
- 自动化测试：`tests/cashier-v3/php/project-replacement-void-contract.php`
- 工程脚本：无。生成物：本地 Vite `dist/`（忽略，不提交）。验收证据：本文件。

## 技术检查

- `php -l`：6 个修改的 PHP 源文件及新增合同测试通过。
- `php tests/cashier-v3/php/project-replacement-void-contract.php`：9 项通过，新增前端动作登记和空上下文检查。
- `php tests/cashier-v3/php/card-operation-kernel-contract.php`：16/0 通过。
- `php tests/cashier-v3/php/order-center-records-readonly.php`：42/0 通过。
- `npm run build`（cashier-v3）：通过，1990 modules transformed；有原有的大 chunk 告警。
- `git diff --check`：通过。
- 本地浏览器 `18091/view_cashier_v3/#/order-center`：在 V3 已完成项目替换记录操作列看到“查看详情”旁的“作废”；点击后直接显示作废原因及确认按钮，未提交真实作废。
- 用户首次点击确认后出现“该操作尚未开放”：定位为前端动作清单遗漏，命令在 `requestCashierV3Action` 本地被拒绝，未到后端；本次已同步前端清单及服务端锁定动作的空上下文路径，随后按单项授权重试成功。

## 经产品经理单项授权的本地作废验证

- 目标：本机 `mohe-cashier-app` 连接本机 `mysql:3306/ruihao`；仅操作 `CK26100700001`（V3 项目替换，原操作 ID `8`），未连接或修改远程瑞昊生产实例。
- 授权：产品经理对该单回复“确认作废”；作废原因沿用其页面填写的“测试”。
- 执行前：来源项目明细 `4346363` 剩余 `7` 次，目标明细 `4476260` 剩余 `1` 次；卡状态版本 `2`，作废审计 `0` 条。
- 执行后：审计单 `ZF2610071334063EEC3A` 状态 `succeeded`，反向业务事件 `EV-c862adbd8fb641d887d205cf39de4d18`；来源剩余 `10` 次，目标剩余 `0` 次且撤销数量 `1`，卡状态版本 `3`。
- 页面读回：订单中心切换“全部”后，该单显示“已作废”，操作列只保留“查看详情”，不再显示“作废”。
- 该持卡人没有新规则项目组件，故本次实操只覆盖旧卡权益路径；新规则卡反向组件仍需隔离数据验证。
- `php tests/cashier-v3/php/order-lifecycle-contract.php`：3 项失败（服务作废前端版本上下文、销售单详情终态、终态冲销人员快照）；均非本轮修改的路径，未计入通过。

## 尚未完成

- 已在上述本地业务数据上验证成功作废；重复提交、目标已核销、来源已预约、来源已变化和新规则卡反向组件仍未完成隔离事务级集成测试；合同测试不能代替这些负例验收。
- 产品经理随后明确回复“我测试没有问题,commit,部署瑞昊,push”，确认本轮固定业务版本并授权按提交、瑞昊部署、线上验证、推送的顺序发布；实际发布结果另行记录。
- 本轮新增或更新的业务注释位于 `OrderCenterView.vue`、`BusinessRecordDetailOverlay.vue`、`cashierV3Bridge.js`、`CashierV3CardRuleEntitlementAuthorityServices.php`、`CashierV3OrderCenterRecordQueryServices.php`、`CashierV3OrderCenterVoidServices.php`、`CashierV3OrderLifecycleModule.php`、`CashierV3ActionManifest.php`。

## 提交前复核

- 上述 11 个源码和测试文件内容未再修改；以本文件“文件分组”顺序对各文件执行 `shasum -a 256`，再将 11 行完整输出执行 `shasum -a 256`，可复算指纹为 `6e6b804d5882d4e6b720c3f3b77f23bc942a3e4c7d061598473b1dba6c98a2ae`。原记录的 `4fb81465...` 未注明清单排序和哈希格式，故不作为发布校验依据。
- 再次运行 `project-replacement-void-contract.php` 通过；本轮代码 `git diff --check` 通过。原 `order-lifecycle-contract.php` 三项失败及未覆盖的负例仍按上文如实保留，不将其记为通过。
