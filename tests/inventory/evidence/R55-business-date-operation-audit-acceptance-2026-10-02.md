# R55 库存业务日期与操作信息闭环

## 验收与版本

- 日期：2026-10-02。
- 基线 HEAD：d0d929ae3c410121965a20f8879a4506f71849ea。
- 产品经理明确确认：『我测试没有问题,commit』。
- 验收范围：库存出入库、请货、调拨、盘点、院装领退的业务日期筛选与业务日期、操作日期、操作人展示；导入记录保留导入审计日期。
- 前端验收指纹（App.vue SHA-256）：3c695c0ad5cfcbbf59ff258306e2b6e4cbd032024362860f15711e6996090d46。
- 历史记录没有可靠操作人快照时显示空值占位，不猜测回填；新增业务记录保存操作人快照。

## 文件边界

- 前端源码：前端代码/inventory-vue3/src/App.vue。
- 后端源码：InventoryManualInboundServices、InventoryManualOutboundServices、InventoryPlatformHqInboundServices、InventoryPlatformHqOutboundServices、InventoryStockCountActorServices、InventoryStockCountServices、InventoryCrossSubjectTransferServices、InventorySalonUsageServices、InventoryV3ExcelImportServices、completion/InventoryBatchMovementFactServices、query/InventoryOperationalUnifiedQueryContract、query/InventoryOperationalUnifiedQueryProvider、query/InventoryStoreUnifiedQueryContextFactory（均位于后端代码/app/services/product/inventory）。本次在相关职责、快照与兼容分支补充业务注释。
- 数据库迁移：后端代码/database/upgrades/2026-10-02-库存V3业务与操作信息分离/，含升级清单、前检查、升级、后检查及 SHA256SUMS。
- 自动化测试：tests/inventory/php/operation-audit-columns-contract.php、tests/inventory/js/operation-audit-columns-contract.mjs、tests/inventory/js/count-date-ui-contract.mjs。
- 工程脚本：无；生成物：本地 inventory-vue3 构建产物，不纳入提交。
- 验收证据：本文件；其余工作区改动不属于本闭环。

## 自测

- 修改的 PHP 语法检查通过；库存前端构建通过。
- 操作信息前后端契约、统一查询注册、盘点日期、Excel 导入、完成事件、手工入库请货、门店请货调拨命令、院装项目选择、周期控件及状态标签相关契约通过。
- 本地数据库升级执行成功，schema_check PASS，invalid_new_fact_actor_rows = 0。
- 18091 实际页面核验：出库、入库、调拨显示对应业务日期周期及尾部操作日期/操作人，查询无接口错误；盘点草稿列表显示操作人『管润芬』。
- 提交前再次运行 operation-audit-columns-contract.php、operation-audit-columns-contract.mjs、count-date-ui-contract.mjs，均通过。
- git diff --check 通过。
- 已知测试限制：旧 cross-subject-transfer-contract 的 HQ 静态字符串断言与已有动态主体类型表达式不一致；宿主机直接集成脚本依赖 /workspace 固定路径，未声称其通过。

## 发布边界

本次仅授权本地 commit；未部署远程实例，未 push。提交哈希与实际文件清单由 Git 提交对象记录。
