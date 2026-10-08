# R32 无原单目录品项退款：固定版本与自测

- 基线 HEAD：`83a3d2c43705995869868dac4259121f9bc7fc2c`
- 文件清单指纹（按下列顺序的 `shasum -a 256` 输出再取 SHA-256）：`e7e672bbf5a1210455c65389d482c8768a88e7bda9642e06210b6dc1557321d0`
- 当前工作区原有其他未提交改动；本清单中的 R32 退款文件也含先前未提交的日期／销售人调整。不得按整文件差异冒充本次单独补丁或夹带其他改动提交。

## 范围与文件

前端源码：

- `前端代码/cashier-v3/src/views/OrderCenterView.vue` `8e8f778f7f8737b374639e13103813c276ca27d833265acd73210aef3b20f10e`

后端源码：

- `后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php` `0562d356352ed5f98dc9129dbcfa02e1ad14badf3ef67f6b6e16d9f3e34db51f`
- `后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php` `68e0b32210b2e778030de715ddf045b14d647e7bf146b2be258535726d369adf`
- `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` `edc7c6b7e43f247a1675ddc14cc0e3a2332b02a9d5ca4ca1ddf4c5414943cb80`
- `后端代码/app/services/cashier/v3/order/CashierV3OrderAttributionCorrectionServices.php` `2e700f26ffa240892373123e0196f9af251bcc7d6e59e74e5be3a83edb35c081`
- `后端代码/app/services/cashier/v3/CashierV3CommandGatewayServices.php` `d869b52c2ae0a47c6095bc018cc7e3be754754183eab5d8fe1f6257e6cab3c20`

数据库迁移（仅本地 `ruihao` 已执行）：

- `后端代码/database/upgrades/2026-10-08-无原单目录商品退款/00-升级说明.md` `56c5e25afc852b2bf921ea0af84f6a83d6db9d5e07081af175d8dc93c3b74a51`
- `后端代码/database/upgrades/2026-10-08-无原单目录商品退款/01-升级前检查.sql` `c00ec64493530929619744263b9695573afebcaed25fcd6584254bfb31de5646`
- `后端代码/database/upgrades/2026-10-08-无原单目录商品退款/02-正式升级.sql` `3571fcd65050c09bc4b13bd43095064361dad1b98a0b698ff7de5f8f500b8d43`
- `后端代码/database/upgrades/2026-10-08-无原单目录商品退款/03-升级后验证.sql` `cbafbab16e20af5dfdaff1fbec59f2dff606d49817a2bcf7bb1939e74bf4eac4`

自动化测试：

- `tests/cashier-v3/js/r32-refund-date-contract.mjs` `1e6b65e358f44a8dd735af09b0f18e8314e691cb6c2213051d1d0004dee98b3e`
- `tests/cashier-v3/php/r32-unlinked-catalog-refund-rollback.php` `f8758226e2622e4f3eeaea327fe66fd516a7c2d75abf8003a38226a45f23ca45`

工程脚本：无。生成物：Vite `dist/`，不作为源码提交。验收证据：本文。

## 数据架构自检

- 权威源是一条有效手工退款及其退款调整记录；最小事实粒度是一笔退款、一个负向现金事实、每名退款销售人一条负向分配。目录只提供当前门店可选 SKU 的名称／类别快照，不充当原销售明细。
- 退款成功终态在同一事务写事件、负向现金事实、退款调整、人员分配事实；业务日可补录，真实发生／结算／记录时间均来自服务端。失败、重试不新增事实。
- `catalog_item_id`、`catalog_kind` 独立保存，来源销售单与明细为空。原销售、库存、卡权益、会员余额均不改；无原单模式拒绝客户端伪造转入余额或作废请求。
- 无原单退款与卡项、单据退款同样允许更正退款销售人及业务日。销售人改派冲销旧负向事实并追加新负向事实；日期更正同步移动退款调整、现金及人员事实，不补造原销售单。
- 幂等键、操作员／门店范围及成功退款记录共同保护重试；重试返回原回执。报告读取现有负向支付及退款销售人事实，列表按退款自身日期和门店权限读取，不借原单猜测归属。
- 性能：目录沿用门店收银目录上限与商品来源；调整表按租户／SKU／业务日建索引。历史卡项／单据退款迁移默认 SKU=0、类别空，不回填或改写旧事实。对账按退款单号核对调整记录、负向支付事实、负向员工事实；如需反向纠错，追加可审计冲销，不删除原事实。

## 自测结果

- `npm run build`：通过，Vite 1991 模块；仅有既有大 chunk 提示。
- `node tests/cashier-v3/js/r32-refund-date-contract.mjs`：通过，覆盖三类手工退款的日期及销售人列表入口。
- 三个改动 PHP 文件和新测试 `php -l`：通过；`git diff --check`：通过。
- 本地 `ruihao` 执行新列迁移，生产未执行。
- R32 无原单事务回滚探针：通过。覆盖项目／产品／卡项目录、无原单快照、拒绝余额和作废伪造、负向现金与薪资、退款列表、幂等重试、销售人改派与日期更正；测试事务已回滚，不保留测试退款。
- 既有 R32 退款销售人回滚探针：通过。R26 卡项退款探针通过；其单据旧夹具当前不可用，未宣称单据路径重新实测通过。
- 本地浏览器：已确认“选择商品”位于查询右侧，弹框列出三类品项；选中项目后显示无原单，隐藏转入余额／作废，销售人候选可用。未点击最终确认退款。
- 本地浏览器：对已有 `TK2610090001` 无原单退款，销售人可打开业绩分配弹窗，日期可打开更正业务日期弹窗；均取消退出，未提交真实更正。前端修改在 `OrderCenterView.vue`，日期后端限制在 `CashierV3OrderAttributionCorrectionServices.php`，相应业务边界注释已同步更新。

状态：开发与自测完成，待产品经理对以上固定版本确认验收。未 commit、未部署、未 push；生产建列需另行授权并先做独立备份和回滚预检。
