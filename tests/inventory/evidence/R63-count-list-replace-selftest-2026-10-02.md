# R63 盘点明细清空与重新载入自测

- 基线 HEAD：`a5ac18d566a3c1e8d6d5dd9754c4144fc64c0a4f`
- 范围：门店端与平台端共用的盘点编辑组件；未触及其他库存业务列表。
- 产品经理于 2026-10-03 回复“我测试没有问题,commit,部署瑞昊,push”，确认本固定版本验收通过，并授权按门禁提交、部署瑞昊及线上验证通过后推送。

## 固定源码与测试

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `92112a26c79ef4ed5b73ecaee95900ba9f3991a76303ab9f0e1526497fab1d43` |
| 自动化测试 | `tests/inventory/js/count-catalog-batch-entry.mjs` | `114e0f02b899b375558d04a88357de0bc75539ad4b9c313e47c219173bdbd6c3` |
| 自动化测试 | `tests/inventory/js/count-batch-entry-contract.mjs` | `79f83d558586d85cf754a74e89430b935a5d93bd9fed64991d66f32449aee8d9` |

无后端源码、数据库迁移或工程脚本改动。`dist/` 是本地构建生成物，不纳入源码提交；本文是验收证据。

## 行为与架构自检

- “清空”只清当前编辑中的盘点明细、旧勾选索引和旧校验状态；不执行库存写命令，也不物理删除此前保存的草稿。
- “导入库存”重新取得当前有库存批次并替换整个列表；“加载全部商品”重新取得当前可盘商品，按批次展开有库存商品，为无库存商品保留待填写行，再替换整个列表。
- 两个加载入口均先完整读取及组装，再原子替换前端明细；接口读取或数据量检查失败时保留原手填行。旧勾选、批量编辑和问题行标记不跨列表遗留。
- 权威库存仍来自现有、按当前账号与仓库约束的后端商品目录；本次仅编辑草稿投影，不产生库存事实、业务日期、事务、冲销或经营统计变化。正式库存仍仅由既有“完成盘点”路径写入，沿用其权限、并发和校验。
- 无新历史数据、跨客户依赖或数据库迁移；本地源码版本可由 Git 恢复。未连接远程实例，因此线上备份、迁移与回滚不适用。

## 自测结果

- `node tests/inventory/js/count-catalog-batch-entry.mjs`：通过；覆盖重复加载重置实盘、仅导入库存批次、读取失败保留原行、清空明细及旧辅助状态。
- `node tests/inventory/js/count-batch-entry-contract.mjs`：通过；覆盖按钮入口与库存批次契约。
- `node tests/inventory/js/count-flexible-import-contract.mjs`、`node tests/inventory/js/count-submission-contract.mjs`：通过；盘点 Excel 导入与提交边界未受影响。
- 在 `前端代码/inventory-vue3/` 执行 `npm run build`：通过；仅有既有 ExcelJS 大 chunk 提示。
- `git diff --check`：通过。
- 18091 曾返回旧的 Vite 模块缓存，导致页面看不到按钮；重启本地 `mohe-cashier-v3-src` 后，检查服务端编译响应包含 `clearCountRows`，并在浏览器重新打开盘点单，确认“清空”位于“导入库存”前。当前列表为空，所以“清空”禁用；这不是按钮缺失。

本次在 `InventoryBusinessModal.vue` 更新“导入库存”职责注释，并为列表替换、清空及失败保护补充业务边界注释。
