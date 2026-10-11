# 第9轮：商品免录有效期与统一选品

状态：开发及本轮针对性自测完成，待产品经理确认固定版本。未 commit、未部署、未 push。

## 范围与口径

- 商品库存设置在“可作为院装耗材”下新增“不管理有效期”，默认关闭并持久保存。旧客户端未传该字段时保留已有值；暂停库存管理不清除偏好。
- 开启后，入库和盘点不要求员工录入批号、生产日期、到期日。混合单逐商品处理，关闭开关的商品仍保留必填校验。Excel 入库执行相同规则。
- 免录商品由服务端生成稳定内部批次，仍按不同入库/盘盈保存独立成本层。成本不能省略，不伪造日期，不改写历史库存、成本或流水；重复请求不重复入账。
- 新盘点按免录 SKU 总量处理。已有真实批次身份不改写；失效目录仅保留原有按真实批次盘亏能力，不新增盘盈权限。旧草稿空批号没有真实批次身份，续编时免录商品可按总量提交，重复明细仍由命令校验拦截。
- 共用商品选择器去除商品编码、条码列，在库存单位右侧显示库存数量；编码/条码搜索和扫码能力保留。出库、院装编辑明细在单位右侧增加库存数量。
- 库存数量来自当前获授权仓库的 `inventory_stock` 良品 SKU 总余额，按其数量精度展示；不取单个批次，不混入其他仓、其他租户或旧库存体系。缺少余额返回 0，数量仅展示，最终扣减仍由原事务校验。
- 平台端和门店端仍使用同一 `inventory-vue3` 组件，没有第二套业务实现。未修改其他收银页面。

## 本地与版本

- 起始 HEAD：`88eea4f10738733e099aaecd2a9ee84371712475`。
- 固定时 HEAD 以相邻 manifest 为准；期间另一闭环新增了发布记录提交 `83cafade`，未纳入本轮改动。
- 开发源：平台 `前端代码/admin/`（18081），门店 `前端代码/cashier-v3/`（18091），共同库存模块 `前端代码/inventory-vue3/`（18086）。
- 本地迁移已在 `mohe-platform-app` 连接的 Docker MySQL `ruihao` 执行，数据库主机名与 `mohe-mysql` 容器匹配；只增加默认关闭的配置列。
- 远程实例、线上迁移、线上备份和回滚点：不适用，本轮未连接远程实例。
- 本地回退：回退本轮文件即可；新增配置列可保留默认值，禁止为回退删除历史业务资料。后续部署必须先执行该迁移再发布依赖此列的代码。

## 验证

- 本地真实数据库事务测试通过：商品 DAO 开关写回及重新读取、空批号/日期入库及盘盈、幂等重试、独立成本层、管理商品拦截、混合单整单回滚、盘亏/零差异、当前开关重新读取、草稿兼容、Excel、门店/总部目录、跨仓/租户隔离、小数数量、失效目录批次身份。
- 所有合成商品、管理员、库存、单据及事实在外层事务回滚；真实商品配置未保存或变更。
- 10 项 JS 回归通过，其中新测试执行实际 Vue2 保存序列化、双端共用模板渲染、列顺序、数量输入框、混合日期录入、免录提交、盘点加载及失效目录行为。
- 7 项 PHP 合同回归通过：入库请求、盘点日期、扫码目录、Excel、失效目录校验、单据冲销、库存总量。可重跑输出见 validation JSON。
- 修改 PHP 文件语法检查及 `git diff --check` 通过。Vue2 新开关模板编译无错误，修改的脚本均解析通过；商品编辑大模板单独编译的 2 条已有 v-slot 错误与起始 HEAD 完全相同，没有新增错误。
- 共用库存 Vite 构建通过，1924 modules；保留已有 ExcelJS 大分块警告。此构建仅验证共用模块，不代表完成 8080 集成包或部署。
- 已知旧测试限制：`salon-usage-project-selector-contract.php` 的最后一项以无空格方法签名作字符串匹配，当前方法实际为 `private function authorizeProject(array $scope, array $command): array`。相关服务及测试与 HEAD 无差异；该项本轮真实执行失败，未修改旧测试来消除失败，不能称全部历史测试通过。
- 浏览器实际核验平台商品开关位置、出库明细列、已加载真实数量的统一选品器。只打开临时表单，没有保存业务单；证据见三张 PNG。
- 门店浏览器原有“办理赠送”未保存表单保留，未重新加载、取消或提交。门店侧验证为实际共享模板执行及真实目录服务测试，不冒充门店浏览器完整业务验收。
- 本地库存服务曾返回旧编译缓存，已刷新并恢复；临时测试标签已关闭。
- 18091、18086 实际返回的共用模块均已检查，包含本轮库存数量及失效目录身份保护代码；未为此刷新用户收银表单。

## 文件分组

前端源码（以下业务文件均新增或更新了相应边界注释）：

- `前端代码/admin/src/pages/product/productAdd/components/inventorySet.vue`
- `前端代码/admin/src/pages/product/productAdd/formModel.js`
- `前端代码/admin/src/pages/product/productAdd/index.vue`
- `前端代码/inventory-vue3/src/components/InventoryProductSelector.vue`
- `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue`
- `前端代码/inventory-vue3/src/inboundDateValidation.js`
- `前端代码/inventory-vue3/src/utils/countSubmission.js`

后端源码（服务注释覆盖免录/成本、当前权威配置、幂等及权限边界；控制器为既有参数白名单的增量）：

- `后端代码/app/controller/admin/v1/product/StoreProduct.php`
- `后端代码/app/controller/store/product/StoreProduct.php`
- `后端代码/app/services/product/product/StoreProductServices.php`
- `后端代码/app/services/product/inventory/InventoryManualInboundServices.php`
- `后端代码/app/services/product/inventory/InventoryStockCountServices.php`
- `后端代码/app/services/product/inventory/InventoryStockCountDraftServices.php`
- `后端代码/app/services/product/inventory/InventoryStoreCatalogServices.php`
- `后端代码/app/services/product/inventory/InventoryPlatformHqCatalogServices.php`
- `后端代码/app/services/product/inventory/InventoryCatalogStockProjection.php`
- `后端代码/app/services/product/inventory/InventoryV3ExcelImportServices.php`

数据库迁移：`后端代码/database/upgrades/2026-10-11-商品免录有效期/01-up.sql`。

自动化测试：`tests/inventory/js/no-expiry-product-selector-contract.mjs`、`tests/inventory/php/no-expiry-local-integration.php`、`tests/inventory/php/manual-test-bootstrap.php`、`tests/inventory/mysql56-matrix.sh`。本轮没有运行完整 MySQL 5.6 矩阵，只同步其测试表字段。

工程脚本：无新增业务工程脚本。

生成物：`前端代码/inventory-vue3/dist/`，只作构建验证，不作为源码正本或手工修复入口，不列入本轮提交源码。

验收证据：本文、相邻固定 manifest/validation JSON 和 `R9-no-expiry-*-2026-10-11.png` 三张截图。

已删除替代路径：两个目录服务中旧的全仓查询后逐规格匹配库存实现，以及因此失效的查询类 imports；统一为当页 SKU 投影。新路径已由总部、门店、跨仓隔离及单位精度测试覆盖。旧辅助类仍有其他调用，未删除。

## 产品经理验收要点

1. 保存商品的“不管理有效期”开关，刷新后状态不丢失。
2. 开启商品可免录三项资料入库、盘点；混合单中的关闭商品仍要求资料。
3. 出库和院装的统一选品器只显示商品名称、规格、库存单位、库存数量，数量正确。
4. 出库和院装明细的单位右侧显示库存总数量，原业务数量仍可正常输入。

上述验收仅针对 manifest 固定文件与指纹；验收后才按项目门禁提交，部署和 push 仍需单独明确授权。
