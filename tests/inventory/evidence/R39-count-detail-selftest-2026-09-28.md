# R39 门店盘点详情：开发自测与待验收记录

- 基线 HEAD：`b356b81cbe5490c565a7acbfad4bd0b5c98fe561`。
- 问题：门店盘点列表的“查看”只向详情弹窗传列表行；门店端缺少盘点详情路由、接口与读取方法，因而显示“此单据详情读取接口尚未接入”。
- 修复：门店端按当前登录门店、在岗员工及默认库存仓读取盘点单；与平台端复用同一只读明细投影，成本单价仍按服务端权限隐藏。未改盘点确认或库存事实写入。
- 状态：开发与自测完成，待产品经理对下列固定版本确认验收。未 commit、未部署、未 push。

## 固定版本文件清单及 SHA-256

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/inventory-vue3/src/App.vue` | `4f42d370098efc5196d8fefb1acff2eb2431243f339b8c2a4623d884c5ceea0d` |
| 前端源码 | `前端代码/inventory-vue3/src/services/inventoryApi.js` | `9b02e310a8a2a1d80ed71f6034aaa22d43b907374091a2478941b462c1b4921a` |
| 后端源码 | `后端代码/app/controller/store/product/inventory/InventoryStockCountQuery.php` | `b3c13c60cd63c218e4f01cadf3c7f054bf96a3e1c801382cc63f772ec5155606` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountQueryServices.php` | `caa3cac04cb7226b78f98bcdcd149b73bfb6e76994cb08434af59edaa7fd180f` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockCountDetailProjectionServices.php` | `538da54dcfcd66bf059eb87f1706642e4a4c3be5a9a56014ffea4a8dca87fbaf` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryPlatformHqStockCountQueryServices.php` | `0ab595dbc7d101392abb78a0429d40c1fa3af4ef8b0642409e5adad5504cee5b` |
| 后端源码 | `后端代码/route/store.php` | `c1ebd96408fead8d2d5dd8bbca5edee135eecdb7ea87850e2e46f24e287dab3e` |
| 自动化测试 | `tests/inventory/php/stock-count-integration.php` | `c57be47fbf6cd6aebd7adc7d9da941c27373a2736b27b6231b1bb1d17a0ed116` |
| 自动化测试 | `tests/inventory/js/inventory-api-contract.mjs` | `455547b8738ef690369a3e8dc16604282fe3a5737f98c2a537e4f0f843aa1a56` |
| 自动化测试 | `tests/inventory/js/inventory-v3-c3-contract.mjs` | `ef0a004d3ababab7f5384d269c8b591faf21a93b9155e243607ed026d177ef80` |

数据库迁移、工程脚本：无。生成物：本地 `前端代码/inventory-vue3/dist/` 构建结果，未纳入源码。验收证据：本文件、以下测试输出及本地浏览器盘点详情。

## 自测结果

- `inventory-api-contract.mjs`：55 项通过，覆盖新增门店详情 GET 路径。
- `inventory-v3-c3-contract.mjs`：通过，覆盖门店与平台详情分流及受保护路由。
- 六个受影响 PHP 文件语法检查、`git diff --check`、库存前端 Vite 构建：通过。
- 原有重复使用的测试库包含固定幂等键和旧业务行，直接重跑集成测试出现 4 项数据污染失败；因此仅在新的本地测试库 `inventory_count_detail_test_20260928` 复制 41 张测试表结构并补齐测试根组织，完整集成测试 11 项全部通过。该库不是客户实例或线上库。
- 本地门店“肥西水晶城”页面实测：点击 `PD2609280001` 的“查看”，详情展示冰袖、拖鞋两条明细，均账面 0、实盘 100、盘盈 +100、批次 `0928001`、生产日期 `2026-06-07`、到期日 `2027-07-07`。当前员工无成本查看权限，单价在页面及接口投影均为隐藏值。
- 详情服务的独立权限测试：非当前门店员工不能读取，成本权限关闭时盘盈单价返回 `null`；没有进行库存写入。

## 数据架构自检

- 权威源与粒度：只读 `inventory_stock_count_document` 单头及 `inventory_stock_count_line` 不可变明细；当前目录关联仅补充展示名称，不推导盘点数量和成本。
- 事件、时间、事务与幂等：本次无写命令和新事实；业务日期及确认/记录时间直接取原盘点单，不改原事务、并发、幂等或冲销链路。
- 权限与快照：门店、在岗员工、租户、默认仓和单据 ID 在后端取交集；平台端继续先校验总部仓；成本字段由后端能力决定，浏览器不能扩权。原单据明细无商品名称历史快照，本次仅沿用当前目录名，未改写历史数据。
- 报表与性能：不改变统一查询列表、统计或导出；详情仅按单据 ID 读取明细，未新增聚合或跨客户读取。
- 对账与兼容：详情明细数量与列表差异项及原盘点单核对为 2；平台端保持既有响应结构，门店端复用相同投影。无迁移。

注释更新：`InventoryStockCountQuery.php`、`InventoryStockCountQueryServices.php`、`InventoryStockCountDetailProjectionServices.php`、`InventoryPlatformHqStockCountQueryServices.php`、`App.vue` 说明了详情权限、只读投影与列表行不能替代详情的边界。
