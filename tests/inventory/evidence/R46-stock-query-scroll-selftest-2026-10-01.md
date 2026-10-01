# R46 库存查询纵向滚动自测记录

- 验收范围：瑞昊门店端库存管理／库存查询结果区。
- 产品经理确认：2026-10-01 明确反馈“我测试没有问题”，确认本固定版本验收通过。
- 页面行为：仅库存查询结果区限制视口高度并显示纵向滚动条；表头保持固定；盘点及其他库存功能不受影响。
- 合同测试：`node tests/inventory/js/inventory-stock-list-scroll-contract.mjs`，5/5 通过。
- 构建验证：`inventory-vue3` 与 `cashier-v3` 执行 `npm run build` 均通过。
- 本地入口：`http://127.0.0.1:18091/view_cashier_v3/` 返回 HTTP 200。
- 变更注释：`前端代码/inventory-vue3/src/styles.css` 已补充库存快照滚动边界说明。

## 固定版本指纹

- `前端代码/inventory-vue3/src/App.vue`：`14758fa921889b64178f9d4b1df542dedcf64da555043c4cf61bde8033376d8f`
- `前端代码/inventory-vue3/src/styles.css`：`3d335b6d9329e6f04704620f2bc813067e461985cbbc8bf6584de6bce747f34f`
- `tests/inventory/js/inventory-stock-list-scroll-contract.mjs`：`3fcaf49dfc287b0ef07e39936bd1b808a25eb7dd69aeaa4fac289adc530c7aa9`
