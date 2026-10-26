# R43 组织下属门店库存读取候选（待产品经理验收）

- 唯一源码基线：`b7d8113bd065ebafba1810211dda5fc99e9adc16`。本轮开始时为 `16f5c187c81fcaf4de1a347cf5e79f482799e77a`；期间其他任务提交推进 HEAD，受影响库存路径未改变。
- 已上线基线恢复：当前 HEAD 中的 `5ea310ad` 曾移除上一轮 R43 的组织/单店选择控件。本候选先从已验收提交 `6f90d08d` 恢复 `App.vue`、`InventoryOrganizationStorePicker.vue`、`documentDetailReader.js`、`inventoryApi.js` 的上一轮内容；这部分是防回退，不计为本轮新增。
- 本轮新增：平台入库、出库、请货、调拨、院装五页的组织节点可查询其下全部有效门店仓；平台端仍默认总部数据，单店筛选仍保留。组织只扩大页面授权后的读取范围，不授予写入。服务端按当前组织树与有效门店默认仓求交集，跨店请货/调拨单只出现一次，详情按每行实际门店读取。
- 业务边界：仅本地开发与构建；无迁移、无远程连接、无生产库操作、无本地 commit、无部署、无 push。瑞昊、渼约 008 的运行版本和备份状态本轮均不适用。

## 源码文件 SHA-256

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端 | `前端代码/inventory-vue3/src/App.vue` | `123a48dddcdf577074df9e001a3a14b5a25db9a85d2dd5b7937f3e7b39778f8c` |
| 前端 | `前端代码/inventory-vue3/src/components/InventoryOrganizationStorePicker.vue` | `0181a71a9c92aef4fa9201a2448cbac1e8da036b79a3584754b38308f76ea857` |
| 前端 | `前端代码/inventory-vue3/src/documentDetailReader.js` | `29aee4d8c2ff3aec196c7cd105699c96610edb668a00da5b2868c370b395f555` |
| 已上线基线恢复 | `前端代码/inventory-vue3/src/services/inventoryApi.js` | `09642096dd4c2578997c864d1d5b2cc6242553665af64e36b7b6826c7845ca1c` |
| 后端 | `后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php` | `d1e5ac0378cc3419eead40f2499d08f3b578b4d2ed709148f7066d384af9ddf9` |
| 后端 | `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php` | `eb2ba601dedfbcde38f06bece5ccd63b9f6f7b6247d267ecb82efc26f825ec18` |
| 后端 | `后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php` | `67eb13d469043de99a59f208ac23f4b8e3b521de021556bea830e25f5aa9d374` |
| 测试 | `tests/inventory/js/platform-organization-store-read-contract.mjs` | `ffbb0d390f79337cc080fe759b63d2afdde1f200b7de5a457503975ac010f2e1` |
| 测试 | `tests/inventory/php/platform-organization-store-read-local.php` | `1e46c39f3ec7bc4f2c64c497a9f2c51b9f6ab81a3d190cd4b75e84e65b9f796d` |
| 测试 | `tests/inventory/php/transfer-in-transit-period-contract.php` | `1069fe4809210730a74d51ddd024981bba0b2a5f2ced2864720d761b8d3e0c57` |

跟踪文件补丁 SHA-256：`7bc427da3862d9848b23e9920591d80ca377b18da18662e76e51e386a035fb7f`；未跟踪的控件文件以其独立 SHA-256 固定。新增/更新注释位于服务端范围工厂、统一投影、控制器、控件、详情适配及测试，说明了只读边界、行归属与日期下推条件。

## 自测

- 本地事务回滚联测：选中一个真实 3 店子组织，五页 2026 年 10 月命中 `10/45/6/5/19` 条；逐页比对组织结果与 3 个单店结果的单据键并集完全一致，合计/分页/只读标志通过。无效组织、组织与门店混选、总部与组织混选、非开放页面均拒绝。请货和调拨详情可读，备注写入被拒绝。
- `platform-organization-store-read-contract.mjs`、`platform-organization-store-read-contract.php`、`platform-operational-query-contract.php`、`platform-document-detail-scope-contract.php`、`transfer-in-transit-period-contract.php` 通过；PHP 修改文件和测试语法检查通过，`git diff --check` 通过。
- 库存前端构建通过：`dist/index.html` SHA-256 `87bbdcc5c4eee46c59b3d7a977c74a58426aa6a3092fef31f3e0c6bda637b8a7`，主 JS `index-72bc9529.js` SHA-256 `ffd1ccef547304493361b3e318f7435da5b517f6c1c96961ccd7a6e9d2fc7cfc`，CSS `index-0de161ac.css` SHA-256 `0de161acaf21de3df4898bea8bed4b987871c1562fb711d67a7b8f20078f23d2`。本地平台 `http://127.0.0.1:18081/admin/stock/salon/usage` 可见默认平台端入口；直接打开库存应用因缺少宿主会话不提供组织目录。
- 仍保留统一查询现有每次最多 1 万条来源行的保护；超限时明确提示缩小业务日期，不静默截断。未执行生产实例业务验收。

状态：开发与自测完成，待产品经理确认此固定候选；确认前不提交、不发布。
