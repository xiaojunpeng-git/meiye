# R42 退款导购/销售经理归属固定版本

- 基线 HEAD：`dac7aed256a477d375a77726fe71c67c6b5c9e79`。
- 范围：退款弹窗在“不作废”右侧增加可编辑导购/销售经理；原单有效归属仅作默认草稿；退款不选轮次；退款记录在退款销售人旁显示本笔归属快照。旧退款不回填。退款金额、原销售归属、导购轮次事实和工资事实不因本字段改变。
- 本轮复用既有 R42 未提交的退款销售人改派工作。共享的 `OrderCenterView.vue`、`CashierV3RefundSalespersonServices.php` 等文件包含前一闭环的未验收改动；不得把本固定版本误认成仅有本次字段的独立提交。
- 补充/更新业务注释：`OrderCenterView.vue`、`PersonnelPerformanceOverlay.vue`、`CashierV3RefundSalespersonServices.php`、`CashierV3OrderCenterRecordQueryServices.php`、`CashierV3RefundAttributionServices.php`。

## 文件指纹 SHA-256

| 类别 | 正本文件 | SHA-256 |
| --- | --- | --- |
| 前端 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `8fc33d8f8e2751d9d95d9270f9e3c06f8ae1cc4e61a9e3f3d2d7ba8e9ffc133a` |
| 前端 | `前端代码/cashier-v3/src/components/cashier/PersonnelPerformanceOverlay.vue` | `7b14ba7dc349a6bab3d58da45c4f0ca8afb6d6d2e715789e5480bab41814d74d` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3RefundAttributionServices.php` | `d22810aa136744198896eb1a73bb5ac986892bc91b5e924de6a2755c85680c64` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php` | `f00e54bea47d36145ebb1cfbc0359de6d29b91e4ce72a1ac255a07432d402e99` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `511779003d8725cd9d1a8459cdaaf83b4ce342157cfe1f7c6aaf57052a93854f` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-11-退款导购销售经理归属/00-升级清单.md` | `500d75cdee73decc590e350a01255cb07593d32df03db0988d2aa5e09192c4de` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-11-退款导购销售经理归属/01-升级前检查.sql` | `1e0f72f14efb2de37e0460069b667c640617d1e6e1bf03513580fa4a3b54735d` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-11-退款导购销售经理归属/02-正式升级.sql` | `e5d175afa546782bce7833283f301126a25afacd43071b56ed480dfaa27e0799` |
| 数据库迁移 | `后端代码/database/upgrades/2026-10-11-退款导购销售经理归属/03-升级后验证.sql` | `ec9fd1e605fac3ae0dc9fc99aa43911c939a7ac113e3e6a90c2a0127c595973b` |
| 自动化测试 | `tests/cashier-v3/js/personnel-performance-overlay-contract.mjs` | `98063f70d7f4a79a213322e242810684cd2c327601e3f73889f223db54897c79` |
| 自动化测试 | `tests/cashier-v3/js/r42-refund-attribution-contract.mjs` | `4c806decee885ff958d216a7d129042565382cce308c20cfceae01070f346f01` |
| 自动化测试 | `tests/cashier-v3/php/r42-refund-attribution-rollback.php` | `5a73ed663a85a2631345f566c710b3ee16cbce3d68c6fab436cf121f66feecc1` |

以上 5 个已跟踪业务/测试文件的 `git diff --binary` 补丁指纹：`360440886e588553c8cc0be034aefa182a024b8ef899d12b22504e83c03e33d4`；新文件以表内 SHA-256 单独锁定。

## 本地自测

- `npm run build`：通过。构建源为当前 `前端代码/cashier-v3`；仅生成本地 `dist`，未做 8080 集成打包或部署。
- PHP 语法检查、`git diff --check`：通过。
- `personnel-performance-overlay-contract.mjs`、`r42-refund-attribution-contract.mjs`、`r42-imported-refund-salesperson-contract.mjs`：通过。
- `r42-refund-attribution-rollback.php`：通过。真实本地原单读取 1 位导购、1 位销售经理；无原单为空；拒绝导购轮次；事务内写入后列表可见；回滚后新记录及原单归属事实计数均恢复。
- 本地 `mysql:ruihao` 已执行本目录新增表迁移；6 个关键列齐全，重复归属键为 0。仅开发库，未连接远程实例。
- 18091 本地页面操作：退款记录新列位于退款销售人右侧；桌面弹窗字段位于“不作废”右侧，窄屏顺序换行；集团搜索、选人、返回表单正常；退款人员弹窗无轮次控件。未点击“确认退款”。
- 既有 `r32-refund-salesperson-rollback.php` 在本地账号权限门禁处提前停止，未进入本次逻辑；该脚本未作为本轮通过证据。完整网关退款仍待具备合规测试账号/固定测试对象后补验，不以静态或局部事务测试代替产品经理业务验收。

状态：开发与上述自测完成，待产品经理对该固定版本确认验收。未 commit、未部署、未 push；远程实例迁移和发布均未获本次授权。
