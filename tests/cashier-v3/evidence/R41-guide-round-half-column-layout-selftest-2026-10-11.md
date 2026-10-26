# R41 导购轮次与两项操作左移自测

- 基线 HEAD：`217200a11472c7942d5c2813c12a01c31a25de31`
- 范围：仅购物车导购/销售经理摘要显示导购已保存的第 X 轮；导购/销售经理、优惠券两项在宽屏共同左移半列，较窄四列布局给欠款控件留间隙，两列布局恢复原位。
- 业务边界：轮次取既有 `guideRoundNo` / `guide_round_no`，0 显示“无轮次”，旧记录缺字段不推断；销售经理不加轮次。未更改人员选择、后端归属、金额或结账快照。

## 固定文件

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `0d632bc01ab295658f6730543f120c5d57eea60261500b76da2ca7e9e4a6be93` |
| 前端源码 | `前端代码/cashier-v3/src/styles/base.css` | `3e4d447067db9ff1594ef6769da092c3f08e0c87026e3e7eb331285a440b7f5e` |
| 自动化测试 | `tests/cashier-v3/js/cart-line-guide-round-layout-contract.mjs` | `49eaaa78d1474438128eb3f9fa6ef46a833133fe3f718e15923a61337075eaa7` |

两份前端源码在本轮开始前已经包含其他未提交的 R41 购物车修改；这里只增加导购摘要与对应的两列定位规则，未回退已有修改。本次新增业务注释分别说明“旧记录不推断轮次”和窄屏欠款间距。无后端源码、迁移或工程脚本变更；`dist/` 为忽略的构建生成物。

## 验证

- `node tests/cashier-v3/js/cart-line-guide-round-layout-contract.mjs`：通过，覆盖选中第 2/3 轮、明确无轮次、旧记录缺轮次、本地选择优先及三种布局断点。
- `node tests/cashier-v3/js/personnel-performance-overlay-contract.mjs`：通过。
- `node tests/cashier-v3/js/cart-line-metadata-layout-contract.mjs`：通过。
- `node tests/cashier-v3/js/cart-line-personnel-summary-contract.mjs`：通过。
- `npm run build`：通过；仅既有的大文件提示。`git diff --check`：通过。
- 热更新前只读测得 1941 宽屏四列间距约 252 px、欠款红块右界 1255 px。热更新清空了原标签的 5 条未结账明细；未提单或恢复旧挂单。
- 真人 UI 验证：在原标签临时加入“面部养护”一行，1941 宽屏测得导购与优惠券各左移约 123 px，导购起点 1288 px、优惠券起点 1540 px；选择张青青为导购第 2 轮并保存后，购物车显示“导购/销售经理:张青青(第2轮)”，文字宽度 246 px、右界 1534 px，与优惠券相隔 6 px。随后删除临时行，确认购物车恢复空白，并把目录恢复至原来的“卡项”。未挂单、未结账。
- 响应式 UI 复核：在 1624 CSS 像素宽度下导购起点 1090 px、优惠券起点 1294 px，均左移约 99 px；在 1280 CSS 像素宽度下切为双列，左移变换为 `none`，导购右界 942 px、优惠券起点 948 px，无横向遮挡。复核后再次删除临时行，恢复“卡项”目录并重置浏览器视口，最终原视口为 1941 CSS 像素，购物车为空。

状态：开发与上述自测完成，待产品经理对固定版本确认验收。未 commit、未部署、未 push。
