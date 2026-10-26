# R39 盘点资料 Excel 往返：开发自测与固定版本

## 范围与数据边界

- 仅将门店盘点编辑器的“导出盘点资料 / 导入盘点”从 CSV 改为 Excel `.xlsx`；不改变盘点提交、草稿保存、库存事实与其他业务页。
- 导出当前表格的 11 列、全部当前行，不按勾选状态截断；导入只替换当前页面草稿。正式盘点仍经原服务端确认命令写入，保留原 SKU 身份、当前门店目录及实时账面库存核对。
- 商品 ID、名称、规格、条码、批次号和日期作为文本，数量与单价作为数值；库存盈亏在 Excel 内展示公式，导入时重新计算并经现有校验。导入拒绝被改动的表头、额外列、不支持的单元格内容和公式，不执行文件内代码。
- 无数据库迁移或服务端接口变更。导入失败时保留现有页面数据，不产生库存写入。

## 固定版本

- 基线 HEAD：`9c2627d3a34ef0a1befb755b738b163d04e5dbb6`
- 前端源码：
  - `前端代码/inventory-vue3/src/components/countWorksheet.js` — `a85d8018a631032c58724094082e77a6581491f6c6e0f0f1889a5acbf6475573`
  - `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` — `1a87def626d55c1039031cb39417963b04520a9c35900478fdbe95fa62f1e434`
  - `前端代码/inventory-vue3/package.json` — `cc878898007ea173253ba39dc12b6236d7423aa4fac14565ab018138e4e02a53`
  - `前端代码/inventory-vue3/package-lock.json` — `0950690424f8f89569f32cff6ff6c4815e89ff56c7ae81aa6ffdf71ffd27ab0e`
- 自动化测试：`tests/inventory/js/count-worksheet-contract.mjs` — `b177b1f2e78456df1bbb9ab96b21a2b75879a4c1d7b3bf67f0c018b7b85494bb`
- 后端源码、数据库迁移、工程脚本、生成物：本闭环均无修改。本文为验收证据，不属于业务源码。
- 补丁指纹：以以上基线、文件清单和各 SHA-256 固定；验收前若文件变化，需重新固定并补验受影响范围。

## 验证

- `node tests/inventory/js/count-worksheet-contract.mjs`：通过。覆盖 1001 行无截断往返、真实 XLSX ZIP 格式、前导零、类似公式的商品文本、数值单元格、盈亏公式、Excel 日期、修改实盘后的重算、异常模板与公式拒绝。
- `node tests/inventory/js/count-submission-contract.mjs`：通过。原“只提交有变动记录”规则未改变。
- `node tests/inventory/js/inventory-api-contract.mjs`：56/56 通过。服务端权限范围和最终盘点命令契约未改变。
- `node tests/inventory/js/inventory-v3-c3-contract.mjs`：通过。
- `npm run build`：`inventory-vue3`、`cashier-v3` 均通过。`git diff --check` 通过。
- 本地浏览器打开盘点编辑器后，加载全部商品时库存服务返回非 JSON，无法取得真实门店行来从 UI 下载/回导文件。未点击保存、完成盘点，也未写入库存；浏览器端到端验证需在接口恢复后补做。
- 新增或更新的业务注释位于 `countWorksheet.js` 与 `InventoryBusinessModal.vue`，说明文本/数值边界、公式约束及草稿不写库边界。

状态：开发与自动化自测完成，浏览器真实数据往返未验证；待产品经理对上述固定版本确认验收。本闭环尚未 commit、部署或 push。
