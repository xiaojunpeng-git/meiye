# R8 盘点导入与库存查询数量对齐：本地自测

- 基线：`6991bb98`；仅本地开发与自测，待产品经理确认验收。未提交本轮改动、未部署、未 push。
- 复现：本地瑞昊数据中，蒙城凤凰城店默认仓（门店 140、仓 81）正数库存 90 条、1614 件；有效商品目录仅覆盖 89 条、1564 件。差额 50 件是已删除但仍有库存的“会员毛巾（灰色）”（商品 27496、规格 17406）。核对为只读查询，未修改客户数据。
- 修复：门店“导入库存”与“一键清零”共用当前默认仓正数批次查询；历史商品按现有批次加载。盘点文件重新导入时，也可匹配当前仓的历史批次。有效目录的零库存商品仍由“加载全部商品”路径读取。
- 结算边界：历史失效目录仅允许本仓已有正数批次持平或盘亏；服务端按事务内实时批次余额判断，禁止新批次、盘盈、伪造批号及跨仓库存。无差异行不产生移动事实；盘亏写原盘点事实和单据，沿用既有幂等键与批次快照。
- 数据架构自检：权威数量来自 `inventory_stock` 与 `inventory_batch`，页面合计仅显示已加载盘点草稿快照；库存查询继续使用已结算批次事实的读模型，未改变报表口径、统计时间、权限、历史事实或迁移。目录失效不会删除已有库存，名称沿用库存批次快照。查询按后端会话门店和默认仓限制，成本仍按原权限返回；现有分页和逐页完整性校验不变。
- 验证：`COUNT_CATALOG_BATCH_ENTRY_OK`、`COUNT_SUBMISSION_CONTRACT_OK`、`STOCK_COUNT_CATALOG_VALIDATION_INTEGRATION_OK`（隔离测试库重复两次通过）；三处 PHP 文件在 PHP 7.4 下语法检查通过；`inventory-vue3` Vite 构建通过；`git diff --check` 通过。集成测试覆盖历史批次持平/盘亏及盘盈、伪造批号拒绝。

文件分组：前端源码 `InventoryBusinessModal.vue`；后端源码 `InventoryStoreCatalog.php`、`InventoryStoreCatalogServices.php`、`InventoryStockCountServices.php`；数据库迁移无；自动化测试 `count-catalog-batch-entry.mjs`、`stock-count-catalog-validation-integration.php`；工程脚本无；生成物为忽略的本地 `dist/`；验收证据为本文件。上述前后端改动均同步更新了入口、范围与失效目录约束的业务注释。
