# R99 库存单据复制候选（2026-11-01）

## 范围

- 基线 HEAD：`5731bbc47ad45884f7c8825dc44e64b1b2d05cc5`（此前仅作废请货单“重开”的已验收提交）。
- 请货、手工入库、手工出库、调拨、盘点、院装领用的正常及作废记录，在“查看”左侧提供“复制”；请货不再显示“重开”。原单仅作为新建表单初值，原单号、状态、日期、操作人、履约、库存事实、批次分配和幂等键不复制。
- 门店仅复制本店可新建的单据；平台沿用现有仓库写权限与组织范围。预售领用出库和院装退回没有同类型手工新建表单，不把它们错误转换为手工出库或院装领用。
- 盘点只带入商品规格及备注，按当前仓重新取账面库存，实盘数必须重新填写；已确认盘点的原模式不可追溯，不推断为原批次盘点。
- 入库、出库授权详情只增补当前商品规格身份供复制；历史事实不变。

## 固定文件指纹

以下路径相对唯一源码正本 `美容源码/`，按此顺序计算 `shasum -a 256` 后再对清单做 SHA-256，清单指纹：`4b7ca3af27a782f33bb05fab4dffa536e9cd9dcf472773abd80d63ee51f83a8f`。

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端 | `前端代码/inventory-vue3/src/App.vue` | `84cbee184ca6ed270497ce08282410ada696f0fe35dbb10f1251b0d4ed3d84a0` |
| 前端 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `6ba1100b52131851e34c5cfba474f146ce4ee3448db1b8e7315fa747de800628` |
| 前端 | `前端代码/inventory-vue3/src/requestReopenDraft.js` | `7c9478f6e32bfb98e4acf24d5bdb5839f7e1361f8e727b12395c4d61551fca98` |
| 前端 | `前端代码/inventory-vue3/src/documentCopyDraft.js` | `3ec1781655a0b7062c1970d03f1dc7ab8926efa0dcc5e9b190cd0d468cc24a24` |
| 后端 | `后端代码/app/services/product/inventory/InventoryManualInboundReadServices.php` | `6700bad09294949cc08ac20ec6064a8d4dc610ffa9329c21e71c48fcdb0ce969` |
| 后端 | `后端代码/app/services/product/inventory/InventoryPlatformHqInboundServices.php` | `59d78b58ed3f9c4eab281d3ba59e21cc988f2ccc41759e1c100d404dff3e822a` |
| 后端 | `后端代码/app/services/product/inventory/InventoryMovementQueryServices.php` | `1b45095b8e686770324cb3cdc0792e2f20e27ed307c1c1fba92016abd75c092b` |
| 后端 | `后端代码/app/services/product/inventory/InventoryPlatformHqOutboundServices.php` | `7f1920ae03875882785253674d388a69e80bb21521e30e135831f74b9ed33961` |
| 测试 | `tests/inventory/js/request-reopen-draft.mjs` | `fb475160197c870b064b053800f849b1193117547543af8741de803b1e2df7e0` |
| 测试 | `tests/inventory/js/document-copy-draft.mjs` | `cbf41eacafad8790d45aeace9c42c60c7c3923e8cd751b65e3fa032e24dfa9c6` |

## 本地验证

- `node --test tests/inventory/js/request-reopen-draft.mjs tests/inventory/js/document-copy-draft.mjs`：通过，覆盖正常/作废请货、门店主体、入出库商品及数量、调拨源规格、无效商品身份和新单字段边界。
- 库存 Vue 3 `npm run build`：通过；Vite 给出既有大包体积提示。
- 四个受影响 PHP 文件 `php -l`：均通过；`git diff --check`：通过。
- 门店本地页面：在 2026-10-17 作废请货单 QH2610170002 上，“复制”位于“查看”左侧；点击后新单带入商品、数量及供货方，日期为新建当日，未提交。
- 之后 18091 本地开发容器异常退出，恢复后仍无法响应请求；调拨及其他类型未完成页面点击验证。18086 库存独立入口可加载，但当前会话无可验证单据。平台嵌入页本次未完成实际点击验证。

## 隔离与后续门禁

`App.vue` 与 `InventoryMovementQueryServices.php` 同时有其他任务的未提交改动；本轮只提交上表对应的复制增量，提交前须隔离补丁并重新核对指纹。未获得产品经理对本固定候选的明确验收前，不执行本地 commit；未授权部署或 push。

本轮注释同步更新在 `requestReopenDraft.js`、`App.vue`、`InventoryBusinessModal.vue`、`documentCopyDraft.js`、`InventoryManualInboundReadServices.php` 和 `InventoryPlatformHqInboundServices.php`，说明权限、复制初值与历史事实边界。
