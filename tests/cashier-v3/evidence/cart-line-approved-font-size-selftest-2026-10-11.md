# 收银购物车指定文字放大一级自测（2026-10-11）

- 基线 HEAD：`217200a11472c7942d5c2813c12a01c31a25de31`。
- 范围：仅标题行商品名称与权益标记、手艺人及第一排操作入口、体验、权益来源说明字号放大一级。金额与其他控件字号未主动更改。
- 前端源码：`前端代码/cashier-v3/src/styles/base.css`。
- 自动化测试：`tests/cashier-v3/js/cart-line-approved-font-size-contract.mjs`；复跑 `cart-line-metadata-layout-contract.mjs`、`cart-line-personnel-summary-contract.mjs`。
- 后端源码、数据库迁移、工程脚本：无。
- 生成物：本地 `cashier-v3/dist/` 构建产物，非源码，不提交。
- 注释：`base.css` 新增仅放大商品名称、不改变金额样式的约束注释；新测试注释说明锁定逐项批准范围。
- 验证：上述 3 条 Node 测试、`npm run build`、`git diff --check` 通过。构建只有既有大包提示。
- 浏览器验证：在 `18091` 收银页读得标题名称 16px、权益标记 12px、来源说明 12px、手艺人与第一排入口 13px、体验 13px。1941px 宽屏目视未见控件遮挡；5 条未结账明细仍在。未修改或结算购物车。
- 状态：开发与本地自测完成，待产品经理对本固定版本确认验收；未提交、未打包 8080、未部署、未 push。

## 固定文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/cashier-v3/src/styles/base.css` | `1bbbe29ea51527ff28b19bde0961d11c146d8ba6bf824b67ae827d8957960452` |
| `tests/cashier-v3/js/cart-line-approved-font-size-contract.mjs` | `215eef2d4af752b13fa13247db80eb33318025a2efe653f50f7583353bf0bda8` |

当前脏工作区的 CSS 补丁指纹：`c2a1a2e874d5d70953b10b05c7ac3372185f187e7d10a9351d8126dff5cf54a5`。其中包含该文件本轮之前尚未提交的改动；本轮新增测试以文件 SHA-256 固定。
