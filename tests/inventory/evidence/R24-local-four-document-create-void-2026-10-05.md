# 第24轮：本地四类库存单据新建与作废实测

日期：2026-10-05。范围仅本机 `ruihao` 数据库；未连接瑞昊线上实例，未部署、提交或推送。门店：扬州欧尚店（store_id=23），商品：丰韵霜支／默认（product_id=602528，stock_id=706），数量均为 1 件。四笔原单与作废审计均保留，供产品经理在本地页面查看。

| 业务 | 单号 | 新建操作 | 作废操作 | 最终状态 | 查看位置 |
| --- | --- | --- | --- | --- | --- |
| 院装耗材领用 | `YZLY2610050001` | 门店端李倩；未关联项目 | 门店端李倩；退回原批次 1 件 | 已作废 | 门店端／库存／院装 |
| 手工入库 | `RK2610050001` | 门店端李倩；其他入库，测试批次 `R24-LOCAL-1005`，1 元 | 门店端李倩；冲销新增批次 1 件 | 已作废 | 门店端／库存／入库 |
| 手工出库 | `CK2610050001` | 门店端李倩；其他出库 | 门店端李倩；退回原批次 1 件 | 已作废 | 门店端／库存／出库 |
| 请货 | `QH2610050001` | 门店端李倩；总部仓供货，总部受理 | 平台管理员权限下通过本地后端领域服务作废；页面刷新后显示已作废 | 已作废 | 平台端／商品／库存／请货；门店端也可查询 |

院装、入库、出库均通过本地门店页面完成新建和作废，列表现场显示“已作废”。请货单通过门店页面新建；因浏览器自动化无法点击跨域 iframe 中的按钮，作废使用同一后端 `InventoryStockRequestServices::voidForHeadquarters` 权限及业务入口，严格校验本地库、单号、供货方、未关联调拨后执行，并在平台页面刷新核验“已作废”。这证明服务端操作和页面投影，不等于人工点击平台“作废”按钮的验收。

库存核对：`eb_inventory_stock.id=706` 在测试前和全部作废后均为 20 件。院装、入库、出库各产生一条原始批次事实和一条方向相反的冲销事实；请货未产生调拨，不改变库存。院装 `eb_inventory_salon_usage_document.document_status=VOIDED`；请货 `eb_inventory_stock_request_document.document_status=VOIDED` 且 `eb_inventory_stock_request_lifecycle_operation.id=4` 为 `VOID`。手工单作废审计保留于 `eb_inventory_manual_document_reversal`，操作者类型为 `EMPLOYEE`、员工 ID 为 490。没有删除任何测试记录。

截图通用报错的原因：组织选店会话 ID 不是 `eb_system_store_staff.id`。本次在库存服务内解析经中间件认证的会话，重新校验当前岗位功能权限，并以稳定的 `employee_id` 写入库存事实和作废审计；未改收银页面。修正覆盖院装、入库、出库、请货的创建/查询/作废必要路径。院装项目目录在刷新后不再显示“库存操作未完成”。

自测：相关 9 个 PHP 文件语法检查通过；`request-transfer-lifecycle-integration.php` 14 项通过，`salon-usage-integration.php` 8 项通过；`git diff --check` 通过。`manual-document-reversal-integration.php` 的隔离库夹具重复执行时累积同 ID 旧批次，第三次运行遇到 `inventory_manual_reversal_batch_ledger_mismatch`，本地四笔真实单据及作废库存对账已通过；该隔离测试不能作为全套通过证据。已补充旧夹具所缺的员工及总部操作者姓名，仍需在清洁隔离库重跑该脚本。

本次新增或更新业务注释：`InventoryStoreAccessPolicy.php`、`InventoryStockRequestServices.php`、`InventoryMovementQueryServices.php`、`InventoryManualDocumentReversalServices.php`；测试夹具说明：`manual-document-reversal-integration.php`。

状态：开发和本地业务实测完成，待产品经理针对当前固定版本确认验收；未 commit、未部署、未 push。

固定验收版本：基线 HEAD `c81de94a4ca25efbf22160cecfc5d6823673ca61`。本闭环仅涉及以下服务与测试文件；工作区其余脏改动不属于本次交付。下列 SHA-256 用于识别待验收文件内容，验收前如任一文件继续变化须重新固定并补验受影响范围。

| 文件（均位于 `美容源码/`） | SHA-256 |
| --- | --- |
| `后端代码/app/services/product/inventory/InventoryStoreAccessPolicy.php` | `06a430516aa5f8e2872e1540d4b1dd17a5e3c445e36ab83a214b106426109479` |
| `后端代码/app/services/product/inventory/InventorySalonUsageServices.php` | `eff5ad1dfe30e40fdead4d792490adad622af10139e10c232c9f5c84dae322e9` |
| `后端代码/app/services/product/inventory/InventoryManualInboundServices.php` | `688157269c90c67d1e958898b04a04bb7d00b49edb7196cc4a561ad86f85c749` |
| `后端代码/app/services/product/inventory/InventoryManualOutboundServices.php` | `500fcb3b9124b5b9488d67d1b1678421fa0eb319a873e5af768aa75fe830279c` |
| `后端代码/app/services/product/inventory/InventoryManualDocumentReversalServices.php` | `1b4b5cbdf976c9241892c3f6a118a62f0620c75d663de4a6033ffd1aa4563c77` |
| `后端代码/app/services/product/inventory/InventoryStockRequestServices.php` | `4781dc8dcaa142c81441d7ca88a72c2e3ef34485230a02fbd2c3dd7116c19e84` |
| `后端代码/app/services/product/inventory/InventoryStockRequestQueryServices.php` | `5e33d79d9ec17ef0f0f4a7ae98e7af8897fd86137eb284fd84cf012b3d33e154` |
| `后端代码/app/services/product/inventory/InventoryManualInboundReadServices.php` | `d3236bb83822724414fd493dc26e6e49ad26d436c11ee80dd094e317a13558c9` |
| `后端代码/app/services/product/inventory/InventoryMovementQueryServices.php` | `a8ad38bd3e3ad074229b25f8dc4ffb189d415001f6a72e5b4e8e15480ebe5923` |
| `tests/inventory/php/manual-document-reversal-integration.php` | `f4ae33c5432fffb47d6b52a7d46ce150b9661f4f7f0f0c41207ae6ea3d5b0cbe1d` |

文件分组：前端源码无本闭环变更；后端源码为上表 9 个服务文件；数据库迁移无；自动化测试为上表 1 个夹具文件；工程脚本无；生成物无；验收证据为本文件及本地库保留的四笔单据、作废审计和库存事实。
