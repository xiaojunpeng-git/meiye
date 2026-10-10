# 收银购物车摘要值颜色本地自测（2026-10-11）

- 基线 HEAD：`217200a11472c7942d5c2813c12a01c31a25de31`。
- 范围：销售人、手艺人、导购/销售经理、优惠券的已选摘要值为黑色；操作名和英文冒号保留蓝色。空值不显示冒号。金额、人员分配和结账载荷未改。
- 前端源码：`前端代码/cashier-v3/src/views/CashierWorkbenchView.vue`、`前端代码/cashier-v3/src/styles/base.css`。
- 自动化测试：`tests/cashier-v3/js/cart-line-personnel-summary-contract.mjs`、`tests/cashier-v3/js/personnel-performance-overlay-contract.mjs`；复跑 `tests/cashier-v3/js/cart-line-guide-round-layout-contract.mjs`、`tests/cashier-v3/js/cart-line-metadata-layout-contract.mjs`、`tests/cashier-v3/js/r15-line-coupon-frontend-contract.mjs`。
- 后端源码、数据库迁移、工程脚本：无。
- 生成物：本地 `cashier-v3/dist/` 构建产物，非源码，不提交。
- 注释：`CashierWorkbenchView.vue` 保留并沿用人员摘要的两人展示业务注释；`base.css` 新增分色职责注释；测试中新增空状态与着色边界注释。
- 验证：上述 5 条 Node 测试通过；`npm run build` 通过；`git diff --check` 通过。构建仅有既有大包提示。
- 运行页：热更新使当前 5 条未结账浏览器草稿清空，已告知产品经理；没有带已选值的购物车可供本轮目视验证，不能据此声称实单 UI 验收通过。
- 状态：开发与本地自测完成，待产品经理对本固定版本确认验收；未提交、未打包 8080、未部署、未 push。

## 固定文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `9ff4b81041e7d18a980004b8b5d40b704d3ca6e503c68f277dbacccd86d209d0` |
| `前端代码/cashier-v3/src/styles/base.css` | `0a6ae7ff105b78a08e6774091e7bb354c2461d82263fb5b1e0f5473f98a028d3` |
| `tests/cashier-v3/js/cart-line-personnel-summary-contract.mjs` | `16faff440267519d94dece30cf306ff80b10f2ed6bc667243fda56e20cfc8f57` |
| `tests/cashier-v3/js/personnel-performance-overlay-contract.mjs` | `3bae904af0d164495d4fa5c422bf385b62de8b1dbdd6867aa2541945676de439` |

已跟踪文件在当前脏工作区的补丁指纹：`5e9a9f2122591e5cdc1ecd6cd3511e571a451a8a9aee01ae42c2dc76d9cfe9d3`。此指纹包含这些文件中本轮以前尚未提交的改动；未跟踪测试以文件 SHA-256 固定。
