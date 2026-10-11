# R42 退款导购/销售经理改派固定版本

状态：开发与自测完成，待产品经理对本固定版本确认验收。未 commit、未部署、未 push。

## 范围与数据架构

- 基线 HEAD：`4a00cf487274dcf88460ac091567a7527ba04679`。本工作区有其他任务与本轮前既存改动，以下文件哈希固定的是完整当前文件，不表示将其中所有改动归为本闭环。
- 补丁指纹：所列 8 个已跟踪源码文件相对基线 HEAD 的 `git diff --binary` SHA-256 为 `2fa49fecfa0657f15c102a1c27f5ed5522ac27c1c7b9818567d97af92aa5e83b`；它也包含这些文件在本轮前的未提交变更。未跟踪源码、迁移和测试按下列逐文件哈希固定。
- 前端：退款记录列表的导购/销售经理字段可点击；复用退款时的人员选择器，不选导购轮次；原退款人员预选，返回修改保留草稿，保存须填原因。仅对卡项、单据、无原单手工退款开放。
- 权威源：已成功退款的生命周期操作与有效退款调整行；初次归属取 `cashier_v3_refund_attribution`，改派后取最新完整版本的人员明细。清空人员也生成版本，不回退到旧值。
- 最小粒度：一笔退款的一个归属改派版本及其中每名导购/销售经理。退款金额、销售人业绩、原销售单及导购轮次均不变；没有新增经营指标。
- 事件与时间：事务内追加 `manual_refund.attribution_adjusted`，业务日期取当前退款归属日，真实发生/落账/记录时间取操作时刻；与退款销售人改派共用聚合事件序号并取最后事件版本加一。
- 并发/幂等/审计：锁定退款操作行，校验归属版本、命令幂等键、当前门店与人员调整权限；旧人员快照、原因、操作者和新人员明细永久保留。无原单的旧退款不猜测原销售归属。
- 权限/性能：服务端强制当前门店与 `cashier.v3.order.staff_adjust`；列表仅按当前页退款号批量读取最新版本，空版本覆盖旧人员，导出复用同一投影。
- 迁移/回滚：新增两张表，不改写已执行的初次归属表。本机 `ruihao` 开发库已建表；远程实例迁移、备份及回滚点尚不适用且未执行。正式发布须逐实例先迁移再启用新读写路径。

## 自测

- `npm run build`：PASS，Vite 生成 `index-38762855.js`，仅有既有大包警告；生成的 `dist/` 不作为源码提交。
- `node tests/cashier-v3/js/r42-refund-attribution-adjustment-contract.mjs`：PASS。
- `node tests/cashier-v3/js/r42-refund-attribution-contract.mjs`：PASS。
- `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/r42-refund-attribution-adjustment-rollback.php`：PASS；使用真实事件记录器，在本机数据库事务内验证权限、首改/二改、清空、列表投影、幂等、过期版本、与退款销售人交叉改派事件版本；事务已回滚，原销售/业绩行数未变化。
- `docker exec -i mohe-cashier-app php < tests/cashier-v3/php/r42-refund-attribution-rollback.php`：PASS；原测试夹具改为选择尚无归属的本地退款，避免碰撞实际已落账人员。
- PHP 业务文件语法检查、`git diff --check`：PASS。
- 本地浏览器：退款列表已显示可点击的原归属，弹窗预选李倩与范玲丽；暂移除导购、进入原因页、返回后草稿保持。未点击确认保存，实际退款记录未改。

## 文件清单与 SHA-256

前端源码：

```text
52467774b4471679213536769a7c168eaab39729811a5d87653628e564f549a5  前端代码/cashier-v3/src/views/OrderCenterView.vue
64b2ddf8918ecc13fb3bc31fac6171c9c7a35652f3db4c119f29dc2b349c23ce  前端代码/cashier-v3/src/services/cashierV3ActionManifest.js
47d4de288815f07183d2e48f3fcd65b566815e03458dc6595e8d7d94dcb7c3a1  前端代码/cashier-v3/src/services/cashierV3Bridge.js
```

后端源码：

```text
7891d138ec4a76867cb644dfa78450004533346f6e9ad6e1ea1a78be8cc0fd19  后端代码/app/services/cashier/v3/order/CashierV3RefundAttributionServices.php
eb5a9592c4d1d77bf082885718fab2a00883406be21094715b7e0cef25517375  后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php
61e2ec1d1736be02602e56e556e084bf0b29895dcf1d304a4c39909f9fa4aa39  后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php
fa0dab1f93c76f79dc848352610916ea84b2543c8591b27fe569d2b225c70842  后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php
1210fbca5d7acee4dfa369998902249a1a556b8db058f4162255d3ce6d2cbb95  后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php
58938ec9e6045fb4c9beeba46d98f013aaa5f204b715719677e3a56b622aac2b  后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php
```

数据库迁移：

```text
8b4cc3230e594a2b99f0e2acd0de9b3c1e96572221e1aa7d10d0a81708a55a5f  后端代码/database/upgrades/2026-10-11-退款导购销售经理改派/00-升级清单.md
fa54e39f0a40ef9895d3c19ca48c1931035f6733eca3ca145c9436ac94d316cb  后端代码/database/upgrades/2026-10-11-退款导购销售经理改派/01-升级前检查.sql
179154446655afa79007d6c2557f494c46cb90be3822607ea8d1dd0d869f36a0  后端代码/database/upgrades/2026-10-11-退款导购销售经理改派/02-正式升级.sql
95d56c20f6518130087f82d8c741180058f433bb1a56c8abf9d52b8fc82f86ea  后端代码/database/upgrades/2026-10-11-退款导购销售经理改派/03-升级后验证.sql
```

自动化测试：

```text
3f0789e38454d5a6b28ae514aeb7ae85d87861ffce7578339f6a9dd27437d054  tests/cashier-v3/js/r42-refund-attribution-adjustment-contract.mjs
62fc3270f07f0c30c16d363982424b03291067553f81a6f676a2609640ced18d  tests/cashier-v3/php/r42-refund-attribution-adjustment-rollback.php
df5ae9bf32c2775d5ab18ffcea06586ba5882330b68a4557cf602bf9921c248b  tests/cashier-v3/php/r42-refund-attribution-rollback.php
```

工程脚本：无。生成物：仅本地 Vite `dist/`，不纳入源码。验收证据：本文件。

新增/更新业务注释：`OrderCenterView.vue`、`CashierV3RefundAttributionServices.php`、`CashierV3RefundSalespersonServices.php`、`CashierV3OrderCenterRecordQueryServices.php`、迁移 SQL、两份 PHP 回滚测试。
