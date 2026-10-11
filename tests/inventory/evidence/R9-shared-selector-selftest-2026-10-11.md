# R9 商品选择控件统一，自测与固定版本 v2

## 交付状态

- 开发与本地自测完成，待产品经理对 `R9-20261011-unified-selector-v2` 确认验收。
- 本版本包含上一批“不管理有效期”的 22 个源码文件，并新增本次共享赠送选品闭环，共 33 个源码/测试/迁移文件。
- 基线 HEAD：`83cafadecb3a2104e60d680f3d95740049323b54`。
- 文件指纹：`baa7ceb53ba3abb7e7d8c9fa8ae7ac0dfb652c260b52c8cf94491e1cb974bbff`。
- 补丁指纹：`d5872d72ab0070a0fc05557ec1d56b1cf85fdf686e96de027d5a256173aed281`，算法及各文件 SHA-256 见同目录 fixed-manifest JSON。
- v1 保留为历史证据，不代表 v2 已验收；相关变化以 v2 为准。未 commit、部署或 push。

## 最新业务口径

1. 出库、入库、盘点、请货、调拨、院装六个库存入口使用同一个 `InventoryProductSelector.vue`；选品资格仅要求“参与库存管理”开启，不叠加销售或审核状态。
2. 赠送的“添加产品”直接引用上述组件，服务端仅取商品列表“销售中、仓库中”的已审核实体产品，完全不要求“参与库存管理”开启。
3. 两类选品都保留认证主体、门店归属、实体产品类型、SKU 有效性及未删除边界。组件统一不等于功能权限、仓库范围或写业务校验被合并。
4. 分类、搜索、分页、多选、库存单位和当前仓 SKU 库存总数量共用展示；移除商品编码、条码展示列，原搜索能力保留。
5. 赠送选品使用 `query-gift-products` 只读投影，功能权限沿用 `cashier.v3.cashier.gift`，不借用库存接口权限。普通收银销售目录与下单规则未放宽。
6. 赠送选品确认只替换产品部分；项目、优惠券、赠送原因、已选产品数量和有效期不被重置。跨页保留选择，重复确认不累加，允许取消全部产品选择。
7. 赠送提交在原事务中锁定重读产品资格；发放/领用、库存扣减、撤销、幂等和事实链仍沿用原有业务，不因选品共用而提前扣库存。

## 自测证据

- `shared-gift-product-selector-contract.mjs`：执行实际 Vue 脚本，覆盖产品 ID 回填、数量/有效期保留、项目及券保留、去重、清空、跨页选择和旧响应隔离。
- `shared-gift-product-catalog-local.php`：限定本机数据库 hostname，合成商品/门店/仓库/管理员；真实查询验证赠送两种状态、未参与库存产品、分页、跨店/回收站/审核/非产品拒绝；验证门店和总部库存目录与扫码仅按库存开关，不限制销售/审核状态。全部外层事务回滚。
- `no-expiry-product-selector-contract.mjs` 与 `no-expiry-local-integration.php`：上一批 Vue2 保存、双端表格、免录日期批次、成本层、混合行回滚、盘点、草稿和 Excel 回归通过。
- 赠送原有搜索、批量前端、赠送权威、产品赠送领用前后端合同回归通过。旧权威测试中已经失效的“会员必须绑定销售草稿/销售目录解除库存禁用”断言同步改为现行命令会员及共享目录契约，未恢复废弃业务。
- 本次 9 项测试运行结果与输出见 `R9-shared-selector-validation-2026-10-11.json`，全部 exit 0。
- 前后端完整 action manifest 共 231 项的 canonical/type/owner/permissionPolicyId 对齐通过；新增查询是 C5 projection、赠送权限。
- 修改 PHP 文件语法检查通过；`git diff --check` 通过。

## 页面实测

- 门店 `18091`：打开赠送，选择用户截图中的会员，仅操作本地草稿，未点击“确认赠送”。
- “添加产品”加载共用五列表格；当前门店目录为 1100 条 SKU、92 页，不与平台商品数量混为一谈。
- 勾选宁国山核桃后翻到第二页，已选数量仍为 1；确认回填赠送清单。
- 数量改为 3，再次打开选品、按名称查询、再次确认，仍保持 3，没有重复增加。搜索结果为 1 条且保留勾选。
- 页面截图：`R9-shared-gift-selector-2026-10-11.jpg`、`R9-shared-gift-draft-2026-10-11.jpg`。
- 完成后移除了测试产品，未留待误提交的测试赠送内容，页面保留空选中的商品选择入口。
- 没有通过浏览器发放真实权益、扣库存、收款或改动真实商品配置。未以“仅赠送权限”账号单独登录进行真人路径复验；该权限口径由前后端 manifest 和真实页面查询共同验证，不能当作全部角色验收。
- 未重测完整赠送发放/领用生命周期；本次真实数据库测试验证产品提交资格，原事务与事实链由已有合同测试回归，不冒充真实发放验收。

## 构建与文件分组

- 前端源码：平台商品库存设置、共享 inventory-vue3 控件/编辑器/工具及导出、cashier-v3 的赠送弹层/产品草稿映射/action manifest，详见 fixed-manifest `groups.frontend`。
- 后端源码：原免录日期服务、库存目录/总数量投影、实体产品资格策略、赠送只读投影/提交资格及权限清单，详见 `groups.backend`；未触碰其他任务的 `CashierShell.vue` 或权益投影改动。
- 数据库迁移：沿用上一批 `2026-10-11-商品免录有效期/01-up.sql`；本次选品扩展没有新增迁移。仅上一批本机开发库迁移适用，没有连接远程数据库。
- 自动化测试：详见 `groups.tests`；工程脚本本次无新增。
- 生成物：共享库存构建 1924 modules，`index-0b3d8430.js` / `index-ab2e173e.css`；门店构建 1998 modules，`index-064f486e.js` / `index-3d8b3d75.css`。均成功，仅原有 chunk 大小警告。
- 构建源分别为 `18086` 的 inventory-vue3 正本和 `18091` 的 cashier-v3 正本；本次没有制作或部署 8080 集成包。目标客户、线上迁移、远程备份与回滚不适用。
- 生成物、测试截图和自测记录不属于业务源码，文件分组与 SHA 清单保存在 v2 fixed-manifest JSON。既有其他任务的脏文件保持原状。
- 暂存区 diff 指纹仍为 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`，与开始前一致；未暂存本轮文件或夹带其他任务。

## 注释与旧路径处理

- 新/更新业务注释位于 `InventoryProductSelector.vue`、库存包 `index.js`、`DirectGiftOverlay.vue`、`directGiftProducts.js`、`PhysicalProductSelectionPolicy.php`、`InventoryStoreCatalogServices.php`、`InventoryPlatformHqCatalogServices.php`、`CashierV3MemberModule.php`、`CashierV3DirectGiftIssuanceServices.php` 和前后端 action manifest；上一批注释清单见 v1 自测记录。
- 全路径扫描确认赠送产品不再从 `session.catalogItems` 创建选项；后端删除销售目录“清除库存不足禁用”旧产品分支，前端旧搜索弹层仅保留项目和优惠券。
- 六个库存入口仍唯一挂载 `InventoryBusinessModal` 内的同一选品组件，赠送通过包导出引用同一个源码文件，没有创建第二份控件、客户分支或 worktree。
