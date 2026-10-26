# R43 挂单列表来源列：本地自测记录

- 范围：挂单页“手机号”后增加“来源”，取挂单当时冻结的来源名称；旧挂单无来源时显示空值，不读取会员当前来源。
- 本地源码基线：`f0140ba049bc4565ec92c2cf9e8508472f8d1294`；未提交、未部署、未 push。热更新入口：`http://127.0.0.1:18091/view_cashier_v3/#/hang`。远程实例、迁移、备份和回滚：不适用。

## 文件分组与指纹

- 前端源码：`前端代码/cashier-v3/src/views/HangOrderListView.vue`，SHA-256 `1d7a5bd158c221918ea24b3e6c57d3e9286a4c0fc6ee4ae3959913db42720c87`。
- 后端源码：`后端代码/app/services/cashier/v3/hang/CashierV3HangCheckoutSourceSnapshot.php`，SHA-256 `eae3f90e98c1bef007d6a5d768f43bea0586517203132dac4efa7099637c9d0b`；`后端代码/app/services/cashier/v3/hang/CashierV3HangOrderListServices.php`，SHA-256 `b1322547327806d2745811d570759c211398eae066c8313233b6eb49f25718d9`。
- 自动化测试：`tests/cashier-v3/php/hang-list-source-column-contract.php`，SHA-256 `0f5c5e54444944c61fc0c1e128138029a5e3299a7d2c46bf2c3676e24ca2e446`。
- 闭环源码与测试清单指纹（按上述路径顺序的 SHA-256 清单再取 SHA-256）：`9b4c170d1d9c2dd8eeb6d8842e3db72a2ce0a2562f96805ca1702e8741f2502b`。
- 数据库迁移、工程脚本：无。生成物：本地构建 `dist/`，不纳入源码清单。

## 验证

- `php tests/cashier-v3/php/hang-list-source-column-contract.php`：5 PASS，覆盖冻结主/次来源、游客防泄漏、旧数据空值、带权限的列表投影和旧查询设置下的列顺序。
- 本地 18091 真人页面查看：表头依次为“手机号 / 来源 / 商品数量”，既有挂单行展示来源“A老客首护”。
- `npm run build`（`前端代码/cashier-v3/`）：PASS，1985 模块；`git diff --check`（本闭环文件）：PASS。
- 只读列表从挂单权威表本身的 `local_draft_snapshot_json` 提取挂单时冻结的来源快照；不回查当前会员字段，避免历史漂移。原列表的门店范围过滤、分页及排序不变；无新的写事务、幂等或迁移。
- 注释：在来源提取方法、列表投影及列排序逻辑补充历史快照和旧设置兼容边界说明。

状态：开发与本地自测完成，待产品经理对固定版本明确验收；不 commit、部署或 push。
