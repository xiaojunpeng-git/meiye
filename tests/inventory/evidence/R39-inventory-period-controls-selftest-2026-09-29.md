# R39 库存周期控件与当天默认值：开发自测

- 基线 HEAD：`b1732190d47c35f97dcd5318f09430e5ec92e2e6`
- 范围：盘点完成日期的服务端读取投影、查询和详情；库存入库、出库、请货、调拨、院装、导入记录和盘点列表；入库/出库流水统计；客户领用的非可领用状态查询。
- 口径：有业务发生日期的列表统一使用周期控件，首次进入默认门店本地当天；盘点使用完成盘点日期 `count_date`，草稿无完成日期，清空周期后仍可检索；库存余额、首页、临期和库龄为当前快照，不套单据日期过滤。客户领用默认“可领用”时必须展示跨销售日的全部剩余可领用量，不按日期筛选；查看其他状态时销售日期默认当天。
- 权威数据与权限：仅向既有服务端统一查询或库存列表接口传日期条件，不在前端计算库存/金额；未改接口权限、数据库、迁移或库存事实。

## 固定文件版本（SHA-256）

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/inventory-vue3/src/App.vue` | `1dc2150268aca3bf7859e506b2d37330e8d200cf3f162e6a1a6e3ef7af761c76` |
| 前端源码 | `前端代码/inventory-vue3/src/styles.css` | `ee055d2870cc47a3cc807a398a71c222b29e80112cb09e639d1618acee15d02f` |
| 前端源码 | `前端代码/cashier-v3/src/views/PresaleClaimView.vue` | `5f2da6f68d0cf74efe504102ffa030d857f0ca7366bdacd60c403ef88f316f67` |
| 前端源码（仅盘点日期变更） | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `ef829ad3907067bc864f21694706fc54078421565b485d64893fa8404aa91b45` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountDate.php` | `865c3a31378f6788d1a17c8a53a5271206e181476cb6e47901de37cd413b4e66` |
| 后端源码（仅盘点日期变更） | `后端代码/app/services/product/inventory/InventoryStockCountDetailProjectionServices.php` | `e6a1d3945f6b863e515b1fae77d12ab7bc33198e785bf66e2551d1b653223659` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryContract.php` | `d75d229dd086bf3ebc794511c468d9b487bc656aa1910efccb31a0d022b67da1` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php` | `9b9a9dc9acce1fc64b1ff5ae5128eefbd4f01ea44fa9092199670a6f8d7e83e4` |
| 自动化测试 | `tests/inventory/js/count-date-ui-contract.mjs` | `12a817699c7d3e0c0e08a0e8ee6e679f671cad3a487c2a8afe54c28774086901` |
| 自动化测试（仅周期断言变更） | `tests/inventory/js/inventory-v3-c3-contract.mjs` | `52fb32fa0f7cefc8d6d3d4c7f2c312280e1d97f0cd943c37aefb993fb1934ad5` |
| 自动化测试 | `tests/inventory/js/inventory-period-controls-contract.mjs` | `44766ed2bfccc93d04c0f0d5c17b0e54285106f3b471c61eadc7cd67c5665bfd` |
| 自动化测试 | `tests/inventory/php/count-date-contract.php` | `c11f0295eefae553d7131429b40030edb130aadc25b29c1eb52387869830c6dd` |

产品经理确认把盘点日期的必要前后端基础改动与本轮周期控件一起提交。表中 `InventoryBusinessModal.vue`、`InventoryStockCountDetailProjectionServices.php` 和 `inventory-v3-c3-contract.mjs` 的指纹均为本次暂存版本；工作区另有金额相关改动，未纳入本闭环。

## 自测结果

1. `php tests/inventory/php/count-date-contract.php` 通过，覆盖草稿空日期、上海跨午夜和旧查询字段兼容（输出的第三方库 PHP Deprecated 警告不影响断言）。`node tests/inventory/js/inventory-period-controls-contract.mjs`、`count-date-ui-contract.mjs`、`inventory-v3-c3-contract.mjs` 均通过；`git diff --check` 通过。
2. `前端代码/inventory-vue3` 和 `前端代码/cashier-v3` 均执行 `npm run build` 成功；仅有 Vite 大包提示，无编译错误。
3. 本地 `18091` 开发服务重启以清除旧 Vite 模块缓存后，浏览器检查：盘点、入库、出库、请货、调拨、院装、导入记录、入库统计、出库统计的周期触发器均显示 `2026-09-29 至 2026-09-29`；临期与产品库龄没有错误叠加单据周期。
4. 盘点实际查询当天仅返回 `PD2609290001`；改为“昨天”仅返回 `PD2609280001`；再恢复“今天”返回 `PD2609290001`。入库和出库统计分别只显示 2026-09-29 的流水行。
5. 客户领用默认“可领用”状态不显示销售周期；切换“已全部领用”后使用同一周期控件并默认 `2026-09-29 至 2026-09-29`。
6. 本次未执行新增、修改或删除盘点单等业务写入。

## 变更分组与门禁

- 数据库迁移、工程脚本：无本闭环改动；后端源码仅包含表中盘点完成日期的读取投影和查询契约。
- 生成物：本地 Vite `dist/`，不作为源码或待提交文件。
- 业务注释：更新 `App.vue`、`styles.css`、`PresaleClaimView.vue`、`InventoryStockCountDate.php` 与盘点查询服务的完成日期、周期、快照及可领用跨日期口径说明；测试中的说明注释同步更新。
- 状态：开发与自测完成，产品经理已确认本次合并的盘点日期依赖与周期控件提交范围；本记录随本地 commit 归档，不代表部署或 push 授权。
