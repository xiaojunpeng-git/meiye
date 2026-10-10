# R41 挂单列表手机号与来源自测

- 基线 HEAD：`217200a11472c7942d5c2813c12a01c31a25de31`
- 范围：挂单时从服务端会员摘要冻结手机号；列表只读取挂单历史快照。旧单无手机号快照时单元格留空；来源只读挂单时选择，空来源显示“未选择”。
- 数据边界：复用 `cashier_v3_hang_order.local_draft_snapshot_json`，不新增迁移、不回填旧单、不读会员现值补展示；同一事务写入，幂等重放不覆盖原快照。列表沿用租户、门店过滤，快照还校验会员 ID。购物车行、金额与最终结账事实未改变。

## 固定文件

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/HangOrderListView.vue` | `80c6403c8851205684b9fe94f17590d5760bdfc82ab992845f0a90f5de91f01b` |
| 后端源码 | `后端代码/app/services/cashier/v3/hang/CashierV3HangSubmissionServices.php` | `6783506ad38f96c33a1da9460c44ffdc76971b4dfcc4cd89bfefc7e2ce7309f3` |
| 后端源码 | `后端代码/app/services/cashier/v3/hang/CashierV3HangOrderListServices.php` | `16ab3b6ebcdd5cbbc6fe5650422b8e830eb501d05b7e287fb65985060c0ee9ab` |
| 后端源码 | `后端代码/app/services/cashier/v3/hang/CashierV3HangMemberPhoneSnapshot.php` | `1051a4211428536a966d604ae9b0734584e5ffa666d9be046123f60ac3579dab` |
| 自动化测试 | `tests/cashier-v3/php/hang-list-member-phone-snapshot-contract.php` | `b1506f713fe5d4fcb76df233e9ecfd0add9deea571fa2719443365a306b783d5` |

本轮更新了上述四个业务源码文件内的快照、会员绑定、旧单兼容和来源展示注释。无数据库迁移或工程脚本变更；`vite build` 产生的 `dist/` 为忽略的生成物，不纳入源码版本。

## 自测

- PHP lint：新增快照类、提交服务、列表服务、新测试均通过。
- 新手机号快照合同：8 项通过，覆盖新单、旧单、游客、会员不匹配、服务端写入路径和来源空值。
- 原有 `hang-checkout-source-resume-contract.php`、`hang-list-source-column-contract.php`、`hang-draft-save-contract.php`、`hang-sale-only-resume-snapshot-contract.php`、`hang-draft-checkout-decoupling-contract.php`、`hang-draft-delete-contract.php`、`hang-order-result-recovery-contract.php` 均通过。
- `前端代码/cashier-v3` 执行 `npm run build` 成功；仅有既有大包体积提示。
- `room-hang-intent-contract.php` 未通过：原测试未加载 `CashierV3CheckoutWorkspaceIdentity`；临时预加载后仍因旧夹具空购物车报错。该测试文件和对应挂单准备服务均未在本轮修改。
- 浏览器独立新标签没有原标签登录态；原收银标签有未结账草稿，因此未切换或创建新挂单。尚无真人页面/数据库写入验证，不能将合同测试等同业务验收。

状态：开发和上述自测完成，待产品经理对本固定版本确认验收。未 commit、未部署、未 push。
