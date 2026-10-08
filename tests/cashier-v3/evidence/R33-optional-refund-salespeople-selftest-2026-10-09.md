# R33 退款销售人非必填自测

- 基线 HEAD：`96eb958bfe358f4149243d6489a19dcb3ef79974`
- 状态：本地开发与自测完成，待产品经理确认验收；未提交、未部署、未推送。
- 范围：卡项退款、单据退款和无原单商品退款可不选退款销售人。原单销售人默认预填逻辑保留；无分配时不产生员工负向业绩，整笔退款仍落支付调整事实并进入非员工经营口径。原销售单业绩分配不改动。
- 数据边界：空人员以 `[]` 保存版本化快照；事后分配与清空仍走原审计、冲销和乐观版本控制。无新增迁移、权限或远程数据操作。

## 文件与指纹

| 类型 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `1d99fe68dece06f5a461b739d6d212ad298c468d8feeba291faace5acbc91174` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3RefundSalespersonServices.php` | `0d7ef96bb6e6a65bb8501dea85811e3bb23a29254e36a677600806624269601a` |
| 自动化测试 | `tests/cashier-v3/php/refund-optional-salespeople-rollback.php` | `a448a8bc2b7448ed7b2881f3b3f584dd05aab70bf56faf6e7599c9f372cfb700` |

已跟踪源码补丁 SHA-256：`c69f173e5e52cb059415ed15646f3d29b2d619d3fd599134cf59dd8072fcb020`。无工程脚本、数据库迁移或需提交的生成物。前后端业务注释分别说明空分配、退款落账与员工扣减边界。

## 验证

- 新增本地事务回滚测试：卡项、单据、目录品项退款均通过空人员提交；核对空快照、零员工扣减、完整负向支付事实和薪资明细零扣减；再验证补分配、清空、版本递增及冲销审计，全部通过。
- 既有 `r32-refund-salesperson-rollback.php`、`r32-unlinked-catalog-refund-rollback.php`、`r33-refund-void-rollback.php` 回归通过。
- 收银 Vue 3 `npm run build` 通过；PHP lint 与 `git diff --check` 通过。
- 测试使用本地 `ruihao` 外层事务回滚，不写远程实例。页面真实业务验收仍由产品经理确认。
