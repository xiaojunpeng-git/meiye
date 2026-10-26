# R36 门店调拨草稿永久删除自测

- 工作区核对 HEAD：`4a8fd8ca4122037ae4eef5ea432c6424ff78062d`。本次接续此前未提交的 R36 调拨草稿编辑闭环，不处理其他任务的工作区改动。
- 产品范围：门店调拨列表中，草稿的“编辑”后加“删除”；只允许真删除未发货草稿。示例业务单 `DB2610090001` 未被本次测试删除。
- 当前阶段：开发与自测完成，待产品经理对下述固定文件版本确认验收；未 commit、未部署、未 push。

## 文件边界

- 前端源码：`前端代码/inventory-vue3/src/App.vue`、`前端代码/inventory-vue3/src/services/inventoryApi.js`。
- 后端源码：`后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php`、`后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php`、`后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php`、`后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php`、`后端代码/route/store.php`。
- 自动化测试：`tests/inventory/js/cross-transfer-draft-edit-contract.mjs`、`tests/inventory/php/cross-transfer-draft-edit-contract.php`、`tests/inventory/php/cross-transfer-draft-edit-integration.php`。
- 数据库迁移、工程脚本：无。`inventory-vue3/dist` 与 `cashier-v3/dist` 是构建生成物，不作为源码提交；本文件是验收证据。
- 本次业务注释：`App.vue` 的新鲜版本令牌边界、`InventoryCrossSubjectTransfer.php` 的删除入口、`StoreCkeckRoleMiddleware.php` 的查看模式白名单、`InventoryCrossSubjectTransferServices.php` 的草稿/事实边界。

## 数据与权限

- 权威源是调拨单头和商品行。删除只对当前调出门店的 `DRAFT` 生效；事务中锁定单头和商品行，用详情令牌拒绝过期页面。删商品行后删单头，任一步失败整体回滚。
- 即便状态仍为草稿，只要已有批次分配、库存移动事实、请货履约或冲销操作记录，立即拒绝，不触碰库存与请货事实。关联请货草稿删除时，请货单本身保留，重新开放其原有可履约判断。已发货/收货不开放删除。
- 列表统一查询、旧列表和详情均由服务端给出 `can_delete`，按钮只是展示；命令接口独立校验门店、状态与版本。既有门店查看模式只对白名单内的调拨命令开放此操作，其他库存写操作不变。
- 本次不新增表、索引、报表读源或跨客户数据范围；不改正式库。永久删除会移除草稿的创建幂等键，因此旧创建请求若被外部重新主动发送，仍可能作为新草稿写入；现有 UI 删除流程不会重新发送创建请求。

## 验证

- 隔离数据库 `inventory_manual_inbound_test_20260730`：集成测试 25/25，通过门店与查看模式授权、跨门店拒绝、旧令牌拒绝、分配/履约/库存事实拒绝、关联请货保留、已发货拒绝。测试事务回滚；未操作正式业务库。
- PHP 契约 10/10、JS 契约 6/6、既有调拨表单契约 6/6；修改的 PHP 文件语法检查及 `git diff --check` 通过。
- `inventory-vue3` 与嵌入它的 `cashier-v3` 执行 `npm run build` 均通过，只有既有大 chunk 警告。
- 已登录的本地门店端浏览器：筛选 2026-10-09 后，草稿 `DB2610090001` 的操作顺序为“查看、编辑、删除、发货”；点击“删除”展示单号及永久不可恢复提示，点击“取消”关闭，原单仍在列表。没有点击最终删除。

## 固定文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/inventory-vue3/src/App.vue` | `d93d5797e661078eebc8627e2fc962663468adc837c33a3a29fdc0c8dfe81c05` |
| `前端代码/inventory-vue3/src/services/inventoryApi.js` | `fa6eb14087101184ad6b57c462b565251b1be11a68436c2fc94232b5503afcae` |
| `后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php` | `1c7ca9aae3e3dff26ff6458e668442a15f65f7f73ca457bbd961dfb29f292ff1` |
| `后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php` | `d8abc9d7d3400aee753ca7b049f2dcfda112e0ade55b1dca9d0b8f4e2fd26381` |
| `后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php` | `85286aa604fa42a39d1fb2ac6fd0461a590790be4f1ff246af045deb9297a670` |
| `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php` | `92296decb72ceb93b1aa89a5468e683cd303a62c41436f938e23158a29e81ff0` |
| `后端代码/route/store.php` | `dcddc30ccf57cf23201b9732b27728db3996f6ef3943a32de977aa0aa4819ce5` |
| `tests/inventory/js/cross-transfer-draft-edit-contract.mjs` | `5532d2b1f11beb3e80090023e6079d4f4796bca00429c5f1250b2666dc1817b2` |
| `tests/inventory/php/cross-transfer-draft-edit-contract.php` | `dc379da24e29db8cbb1c43ac89ab40d30694187a2a6110c191d381d043aa42e9` |
| `tests/inventory/php/cross-transfer-draft-edit-integration.php` | `5916133c89616380e0e42c0ddd28ffb6e62ed4dfac096298b01097402916aa65` |
