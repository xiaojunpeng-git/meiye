# 第99轮：复制单据切换主体后保留商品

- 固定基线 HEAD：`68e50e2c53d3489ad3bc140d1a05e6899e52cf5b`
- 本次业务补丁 SHA-256（仅下列三个文件的 `git diff --binary HEAD`）：`4a39d270fbae6decbf9500e4ceb60570023f58f0abd038ecfac9e2c2f4408dde`
- 前端源码 `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue`：`cafa72c4dd4c49aef79f1e4f7ac6462ed7aa2fa042d7df9e98972b08a484d165`
- 前端源码 `前端代码/inventory-vue3/src/documentCopyDraft.js`：`1c87e50fdbf5e13bf0de6c103a098feefbb2d7a3c090356017b996fe986da87e`
- 自动化测试 `tests/inventory/js/document-copy-draft.mjs`：`a4cfe7984ae10fb67d9c147aa39a6af112a8ad7d7ea42ab577ecf1ba854b8026`
- 后端源码、迁移、工程脚本：无；生成物 `dist/` 不纳入提交。

## 本轮修复

1. 请货复制表单切换请货方或供货方后，保留已带入的商品、数量与备注；普通新建仍执行原有重新选品行为。
2. 入库、出库、调拨、院装复制表单切换仓库或调出方时同样保留商品明细。原主体当前不可选时留空，待用户选择有效值；原单不可用的核销项目也留空。盘点复制逐条带入当前仍有效的规格，按当前库存重新盘点。
3. 其他单据复制时，单条缺少商品身份或有效数量则只略过该条，有效条目继续带入；入库批次和成本仍与各自原始明细对应。原单事实、状态及幂等键不带入新命令；提交继续由后端核验当前权限、库存及商品有效性。

## 自测与限制

- `node --test`：复制明细、请货复制、院装可选项目 3 组通过。
- `npm run build`：库存 Vue 3 工程构建通过；`git diff --check --` 对本轮三个文件通过。
- 额外执行的旧测试 `cross-transfer-draft-edit-contract.mjs`、`shared-document-layout-render.mjs` 失败；前者断言旧禁用表达式，后者 SSR 桩缺少 `canCopyDocument`，核对 HEAD 中已有对应源码，均非本补丁造成。
- 本地浏览器控制接口返回 `browser.user is unavailable`，未完成平台页面点击复测；未创建新单、未改业务数据。固定候选待产品经理确认验收后仅本地提交。

本轮注释更新：`InventoryBusinessModal.vue` 的切仓和复制调拨提交边界，`documentCopyDraft.js` 的单行过滤边界。
