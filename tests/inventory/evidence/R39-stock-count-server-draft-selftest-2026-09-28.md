# R39 门店盘点服务器草稿：开发与自测交接

状态：开发与隔离自测完成，待产品经理确认验收；未提交、未部署、未推送。基线 HEAD：`2f200bbe3642339625bbadeefa18e5f860baf624`。

## 范围与数据边界

- 前端源码：`前端代码/inventory-vue3/src/App.vue`、`components/InventoryBusinessModal.vue`、`services/inventoryApi.js`。顶部“保存草稿”调用服务器接口，盘点列表草稿可重开继续编辑；已确认单据仍走只读详情。恢复草稿时恢复全部选中 SKU、实盘数、批次、成本和日期。
- 后端源码：`app/controller/store/product/inventory/InventoryStockCount.php`、`app/services/product/inventory/InventoryStockCountDraftServices.php`、`InventoryStockCountServices.php`、`InventoryErrorMessage.php`、`query/InventoryOperationalUnifiedQueryProvider.php`、`route/store.php`。草稿按门店、默认仓、创建人隔离，版本号防覆盖；草稿只写独立快照表。正式确认在原库存事务内锁草稿、验证内容与版本，成功才产生库存及移动事实并关闭草稿。
- 数据库迁移：`后端代码/database/upgrades/2026-09-28-库存盘点草稿/00-升级清单.md`、`01-正式升级.sql`、`02-升级后验证.sql`。仅在本地 `ruihao` 库创建空草稿表；客户实例未执行。迁移前需逐实例授权、备份及回滚检查。
- 自动化测试：`tests/inventory/php/stock-count-draft-integration.php`、`manual-test-bootstrap.php`、`tests/inventory/js/inventory-api-contract.mjs`、`inventory-v3-c3-contract.mjs`。本轮补充或更新了上述业务源码中的职责、权限、事实边界和兼容注释。
- 工程脚本：无。生成物：`inventory-vue3/dist/` 本地构建产物，未纳入源码。验收证据：本文件；本地隔离测试库数据不是客户实例数据。
- 同一盘点列表的详情依赖：`InventoryStockCountQuery.php`、`InventoryStockCountQueryServices.php`、`InventoryPlatformHqStockCountQueryServices.php`、`InventoryStockCountDetailProjectionServices.php` 及其合同测试，使草稿可编辑、已确认单据可只读查看；顶部完成按钮上移的既有待提交改动也在同一盘点闭环中复核。

## 数据架构自检

- 权威源与粒度：草稿表仅是创建人可续编的输入快照；已确认单据、盘点明细、库存主体/批次和库存移动事实仍是权威源。每个真实盈亏 SKU 才有一条正式盘点明细与对应移动事实。
- 时点与事务：草稿记录编辑时间但不产生经营事件；最终确认沿用原业务日期、真实确认/记录时间，并在单事务内校验库存、写单据和事实及关闭草稿。重试按草稿 ID 构造幂等键，版本与内容指纹阻止重复或替换入账。
- 冲销与恢复：不覆盖既有正式历史；草稿确认失败保持可编辑，成功后不能改写。正式盘点的后续调整仍须走原有可审计库存业务，不通过草稿回改历史事实。
- 权限、快照与查询：门店、默认仓、在岗创建人由服务端限定；草稿保存选中 SKU 名称等展示快照，正式确认仍复核当前有效库存商品。列表仅向创建人展示草稿，平台与经营报表不将草稿计为正式事实。
- 性能与对账：独立索引支持门店/仓/创建人/状态的草稿列表；大批量内容保存在一张可续编快照，不产生每行库存写放大。确认后核对正式明细、库存余额/批次、移动事实数量；旧单据与请求指纹保持兼容，无历史回填。

## 技术自测

- PHP 语法检查：草稿服务、盘点确认服务、门店控制器通过。
- `inventory-v3-c3-contract.mjs` 通过；`inventory-api-contract.mjs` 56/56 通过；Vue 3 `npm run build` 通过。
- 隔离 MySQL 集成测试 9/9 通过：草稿保存不改库存/事实、详情恢复、列表可见、越权拒绝、旧版本拒绝、确认内容防替换、确认原子关闭及幂等重放、已确认禁止修改、1001 行完整保存且只计真实差异项。
- `git diff --check` 通过。浏览器交互验收未完成：当前本地页面的库存菜单点击未展开，不能把合同及接口测试冒充产品经理页面验收。
- 旧 `stock-count-integration.php` 在已重复使用的隔离夹具库里出现 4 项非幂等夹具失败；未将其报告为通过，也未据此改写既有测试或业务逻辑。

## 固定版本指纹（SHA-256）

| 文件（相对 `美容源码/`） | SHA-256 |
| --- | --- |
| `前端代码/inventory-vue3/src/App.vue` | `dcb603c7c071cb927ed110ca89ef7ba090fbcb8e019b50131b7d16f2c2a11aea` |
| `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `71fa0a7109e8fc5b3862bb8016645f52aed5cca083649e846272f8874d187944` |
| `前端代码/inventory-vue3/src/services/inventoryApi.js` | `239b6062ecdadf47eb1ab714a93da1d2c74b25defe732ce5ee81b1d708f47451` |
| `后端代码/app/controller/store/product/inventory/InventoryStockCount.php` | `76b0dd51aecb77678b38f455744cd948ac8e897653e9e03a1b9dd58ae3f817d8` |
| `后端代码/app/services/product/inventory/InventoryStockCountDraftServices.php` | `d83d658b311ca2aace3e9ec5de3d469350d40eac67c9b4444ebce213b6a94df3` |
| `后端代码/app/services/product/inventory/InventoryStockCountServices.php` | `fb97ea77a6468e72434f748b173e8d7ed76effb3d3ff75a9aa4eb38fb026b7ed` |
| `后端代码/app/services/product/inventory/InventoryErrorMessage.php` | `f96b8d0e1a04b4cafc96ef1804948d0ee80d400e6c14d99aebae81e838d50f84` |
| `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php` | `9a0869d34d2adec29a46508c016237a645dec3ac10520eab0986914dd77c08b3` |
| `后端代码/route/store.php` | `e24a97b569054a09a0514e4e4740dbd7ec057ddcaebe6fce35e8fe17a4add30e` |
| `后端代码/database/upgrades/2026-09-28-库存盘点草稿/00-升级清单.md` | `c674586bae1f0d7b37577aad3fa7eec0f389c8c347a61a5d4df307e069df727e` |
| `后端代码/database/upgrades/2026-09-28-库存盘点草稿/01-正式升级.sql` | `8aabd1bdaa9aa99059d2379a51bef3a6074d6464c3f095a100ebcebdea9d13ff` |
| `后端代码/database/upgrades/2026-09-28-库存盘点草稿/02-升级后验证.sql` | `ab36e4ec4dca91bb1b10909e2072e98bb1c2e5337fbf57a9b8392ac8f4dd4615` |
| `tests/inventory/php/manual-test-bootstrap.php` | `42de0147a2f454191943bfdd9cbef2a65037dce76f47c9cb63683ad29540916d` |
| `tests/inventory/php/stock-count-draft-integration.php` | `073d9176d2b03d089a5fc81b7b0410623b9226fabefcb5cabc298fa8d967728b` |
| `tests/inventory/js/inventory-api-contract.mjs` | `5bbdb8252646485baf954d97c54d908453b71d832eab1087a09a29b2c26b930d` |
| `tests/inventory/js/inventory-v3-c3-contract.mjs` | `406bbf1c35d4f5e638125e067f5d4036732c00dbe7e2746f638f2c9e63d25b74` |

工作区另有大量其他任务的未提交改动；提交时必须按本闭环逐文件及逐补丁块复核、暂存，不得使用整库暂存。
