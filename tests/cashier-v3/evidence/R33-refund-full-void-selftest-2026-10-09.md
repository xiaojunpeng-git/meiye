# R33 误录退款完整作废：本地自测与固定版本

- 基线 HEAD：`1fc28c4e5a4718b8e199306cffa18c26249607f3`，工作区未提交。
- 本闭环已跟踪文件的 `git diff --binary` SHA-256：`5de712a188885aec66ad6b89880988c24362d32f367edaae883f3b9cb2054f3e`。新文件校验值见下表。
- 范围：退款记录“查看详情”右侧增加作废；事务内恢复原卡项权益和转入赠金，冲回退款现金与退款销售人事实；正常列表、市场业绩、员工薪资及院店退款台账排除已作废退款，全部列表保留原记录。
- 权威数据：退款生命周期操作、退款调整、作废审计、业务事件及三类事实；未回写原销售人分配。无数据库结构迁移。

## 文件清单及 SHA-256

前端源码：

| 文件 | SHA-256 |
|---|---|
| `前端代码/cashier-v3/src/components/order/BusinessRecordDetailOverlay.vue` | `b4f87e1a71bc7d58a56129b2bbbe7c39eb0d2c088b2f4edef62b01aed911f847` |
| `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `16d79353b8a1ed526d5dae3963853f448959ad32ee67bff9f1a398b0bd81d34e` |
| `前端代码/cashier-v3/src/services/cashierV3ActionManifest.js` | `c3a8a9f2a309132928513489ca5d01e49c566cb8efdecffbe2320f0254d2039f` |
| `前端代码/cashier-v3/src/services/cashierV3Bridge.js` | `f5e1fe21750f8983a2c3f2301ff32867ca189ab7cdb84acfc2308374731b7f9e` |

后端源码：

| 文件 | SHA-256 |
|---|---|
| `后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php` | `2c8ef123e7d771837cbf48b2a0da4accb5243cd702f2dae05e2a1af3a9ade33d` |
| `后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php` | `fcdf1bec7e394a35087947eac3e57b72bffe34a17964e95f3ea0818bb7af78c3` |
| `后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php` | `77a8dc5b62ec3f6ff3f858c133e419492c278b93c97ccab3ea5f3b46e366dbe4` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php` | `e448d42b35f9833d454227902944078caf523edd182cd6d188909592b48e993e` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterVoidServices.php` | `9fb3a743cd30fc3efa4bb6d2677a3a31fccd89879b3ea6e73fe666dd1fcc095b` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `2f4f5350b2183846b08585b68d5c02c01bea6f1fb3d623b41ffda9c586f212ff` |
| `后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php` | `1c8ce5ef287d09fedbde4510a7a5fb4b2bc1448c7e3ffc1d8615e1b0064fdf51` |
| `后端代码/app/services/cashier/v3/order/CashierV3RefundVoidServices.php` | `ee850b7293e45a9f77cb41723dc35420cb715f8837c5eb08f5302bd3856df9a8` |
| `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `50313aedae96c4bd907b2d7dd5d5f5e7113582cc9297e686a920c3dc48fd9e6e` |
| `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php` | `bed0e696f035409d76608bbda09a61340541f92fc2ca84e601e4a4267e2add28` |
| `后端代码/app/services/user/UserBalanceAtomicServices.php` | `881bca2f7082999ee370b4a4f5efd1fd82355e19fa5005c10fadecf277ff3e05` |

数据库迁移：无。自动化测试：`tests/cashier-v3/php/r33-refund-void-rollback.php`，SHA-256 `e01b6d8e93b6b7641746fc87b4081f9a90ba72562fa1fa30c8f44910edc5b4c0`。工程脚本：无。生成物：本地 Vite `dist/` 构建产物，未纳入源码。验收证据：本文件。

本次业务注释补充于退款作废服务、退款权益快照、作废入口、网关重放守卫、报表过滤、余额原子服务及前端按钮/弹窗；说明事务、幂等、审计、权限与不可猜测的恢复边界。`RegisteredMetricReadServices.php` 在动手前已有其他任务的未提交改动，本次仅在退款指标查询追加已作废过滤，不回退既有改动。

## 本地验证

- `r33-refund-void-rollback.php`：无原单退款作废与同键重放、卡项部分退款作废、整卡退款作废、单据退款作废、市场 K 业绩恢复、薪资扣减消除、院店退款台账排除，均通过。
- 同一回滚脚本：余额后续收支、卡项权益变动、旧退款缺少完整快照，均返回具体拒绝原因；失败后原退款仍生效且不留部分作废审计。不同键重复作废被拒绝。
- 测试使用本地 `mysql/ruihao` 外层事务并全部回滚；复查 R33 标记的退款/作废记录均为 0。没有连接或改变远程实例。
- 现有 `r32-unlinked-catalog-refund-rollback.php`、市场退款调整集成、院店退款台账集成、R26 页面网关与 R32 日期合同测试通过；PHP lint 与 `git diff --check` 通过。
- `前端代码/cashier-v3` 的 `npm run build` 通过。当前本地浏览器实际停在登录页，未借用会话做视觉/真人路径验收；R26 旧脚本的单据固定夹具已失效，本次用 R33 独立单据回滚测试覆盖。

## 边界与状态

- 旧卡项退款若没有退款前后权益快照，拒绝自动作废并说明缺少可核对资料。退款转入赠金后只要会员余额有后续收支，也拒绝自动扣回，避免猜测这笔赠金是否已被使用。
- 开发与自测完成，待产品经理对上述固定版本确认验收。未 commit、未部署瑞昊、未执行生产库操作、未 push。
