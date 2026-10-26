# R14 库存多仓闭环最终验收索引

- 产品经理于 2026-10-13 确认“我测试没有问题，本轮 commit”，验收范围为本轮总部多仓、请货/调拨/盘点、入库/出库/院装选仓、仓库设置入口移除及两端库存查询一致性。
- 本地提交前 HEAD：`5754b8b5a1c82d3e225825c826c3cfe16569d709`。本轮 52 个源码、迁移与测试文件的 SHA-256 行按路径排序后再取 SHA-256：`77521732be0d64276ef33b168fc0c6a6802797d533fb21d78ba05607c3300d4b`。
- 文件范围：原 [40 项固定清单](R14-fixed-manifest-2026-10-13.md)，加 [入库、出库、院装选仓补充清单](R14-inbound-outbound-salon-warehouse-fixed-2026-10-13.md) 的 3 个后端文件与 2 个测试文件，以及 [库存查询一致性补充清单](R14-stock-query-consistency-selftest-2026-10-13.md) 的 3 个前端文件与 4 个测试文件。合计前端 11、后端 25、迁移 4、测试 12。重复列出的文件仅计算一次。
- 前两份旧清单是当时快照；本索引覆盖其“待验收”和旧指纹状态。验收后只把 `tests/inventory/php/multi-warehouse-rollout-contract.php` 中已经过时的“仓库设置必须出现在导航”断言改为“导航无入口、原设置页面逻辑保留”。业务源码、迁移、页面及接口未因此改变；该测试现为 7/7 通过。
- 本地复验：多仓传输 18/18，库存分页 9/9，数量筛选 19/19，设置入口 17/17，库存滚动 5/5，统一查询注册 14/14，院装项目选择 5/5，读模型 10/10。`git diff --check` 对本轮文件通过。此前隔离 MySQL 39 项与本机三仓实物库存对账见上述证据及 [三仓实测记录](R14-three-warehouse-live-2026-10-13.md)。
- 本轮交付仅允许本地 commit；未获部署、远程迁移或 push 授权。其他任务的已暂存和工作区改动不属于本轮。

注释复核：本轮业务边界注释涉及 `InventorySalonUsageServices.php`、`InventoryPlatformSalonUsage.php`、`InventoryBusinessModal.vue`、`documentDetailReader.js`、`App.vue`、`vite.config.js` 及对应合同测试；本次测试修正的注释说明仓库设置入口与旧页面逻辑的边界。
