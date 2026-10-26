# R14 库存五类单据备注修订本地自测

- 固定基线 HEAD：`ecd9000cda40b4b9ce882b3651d4dc9ec4bae7db`
- 闭环：入库、出库、请货、调拨、院装的主项与明细备注可独立修订；仅操作权限范围内写入，保留版本与逐次审计。请货/调拨整单编辑后的明细行 ID 会在同一事务中重建备注投影。
- 数据边界：手工入库/出库仅更改独立备注投影；请货/调拨/院装同步单据原备注列供现有列表读取；均不重写库存流水、数量、金额或结算状态。产品经理追加确认：能使用对应功能且单据位于可访问仓库范围内即可编辑备注，不另设管理/新建权限；平台可访问的门店仓单据同样适用。
- 本地数据库：已核对本机应用使用 `mysql/ruihao/172.18.0.2`，只在本地 `ruihao` 建立两张新表并校正 3 个快照列为 `MEDIUMTEXT`。回滚集成测试不保留测试写入；本地人工验收单据另有真实备注记录。未连接或修改任何远程客户实例。
- 发布顺序：每个被点名客户实例先备份与执行 `01-正式升级.sql`，再部署代码；本次没有执行任何远程迁移、部署或 push。
- 验证：`npm run build` 成功；`document-line-remark-contract.mjs`、`document-remark-api-contract.mjs`、`shared-document-layout-contract.mjs` 成功；本地 MySQL 集成测试覆盖五类单据、旧单主/明细修改、版本冲突、异单明细拒绝、平台有权门店仓编辑、跨页隔离、只读委托出库可看不可改、草稿重基、库存事实与数量不变及外层回滚，全部通过；相关 PHP 文件 `php -l` 与本闭环 tracked diff `git diff --check` 通过。追加修复后集成测试再通过，包含过滤后的数字字符串与非法小数行号。
- 既存测试差异：`inventory-api-contract.mjs` 和 `document-lifecycle-frontend-contract.mjs` 分别调用当前 API 不存在的 `cancelCrossTransfer`、`reverseCrossTransfer`，在进入本闭环断言前失败；本轮未改这些旧测试或对应旧业务接口。
- 未覆盖样本：本地库没有总部仓院装领用单，因此总部仓院装备注的真实单据回读仍待验收；门店院装的读写与审计已实测。
- 页面验证：本地 `18081` 内嵌 `18086` 出库单 `CK2610130001` 的备注保存后刷新显示，版本 1 且仅一条审计。门店 `18091` 院装单 `YZLY2610130001` 首次保存被错误拒绝：门店会话中间件自动注入 `store_id` 且将 JSON 数字转为字符串，触发严格白名单。仅去除已标记为会话注入的字段，并对版本/明细行号做无损十进制转换后，原输入 `4235哥哥` 保存成功，接口返回版本 1，列表与本地库一致，审计仅一条；库存事实与余额不受备注命令影响。页面证据：`R14-store-usage-remark-2026-10-13.jpg`。产品经理仍须在当前固定版本上确认。
- 状态：开发与本地自测完成，待产品经理确认验收；未 commit、部署或 push。生成的 `dist/` 为派生产物，不纳入源码闭环。

## 闭环文件 SHA-256

```text
a023bdffd38096ba69ef8b5e646fb43fac27d775e4e5fceeed575eb568db66ab  前端代码/inventory-vue3/src/App.vue
d16448fc90cf512b1e8c83b7c66d8a1f0996e652e29fb0f69d805cb39c54090a  前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue
3f4b42c8c20071481d314e0111eed695ca9f6459064ec2acd6bc784bd9754e6e  前端代码/inventory-vue3/src/components/InventoryRemarkModal.vue
1e9a7a49e42e09180c7c63234d59f3383b4a3fc8fcb08fdc2e0bc6e45ebe2e87  前端代码/inventory-vue3/src/documentDetailReader.js
abb30876542823ce554ce8604f78a475a17431b9f864b9fe20eec35149b9d59c  前端代码/inventory-vue3/src/services/inventoryApi.js
bd5ed057c73103bbfcefdc011b9a60677a9d59adf373204a0d9eaa63f52383ee  后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php
212cf37af75caf9e4116df5f805deeee697b9bd2563b1e6525ac63495127662e  后端代码/app/services/product/inventory/InventoryDocumentRemarkServices.php
3a23c6874df68698ae53686177c2ee51b4a5e69b64878f0d66f0332634ae4ede  后端代码/app/services/product/inventory/InventoryManualInboundReadServices.php
fdbe030cd7a5487355c29a18a5f30c02a6662ae8166ef9256cdbe83f287c8a22  后端代码/app/services/product/inventory/InventoryManualInboundServices.php
7c54a9cae1cba19ebe6f1944f7ee39806cc13f364279ad03807779705b979ce8  后端代码/app/services/product/inventory/InventoryManualOutboundServices.php
b6a1e3e341d124a3ccfba6f69af78e3b20777645b89f5daa0946a0ff5fc3fb3c  后端代码/app/services/product/inventory/InventoryMovementQueryServices.php
b8144486e434f74759deec287d917a1a84b6f12efccecbaff4059f9fe251c2c9  后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php
a07f6b9493e6ecc95e47fdbd803bcac974d190f118f78e044c6640a240faeb83  后端代码/app/services/product/inventory/InventoryPlatformHqInboundServices.php
6f87258d1b675f5597335d189ee5d3dd69287b9b275bc19f1ae1604a20c60463  后端代码/app/services/product/inventory/InventoryPlatformHqOutboundServices.php
54a50e32e5746d89221b60469c96dff2134e909095d4e17c59ab274897aab2fd  后端代码/app/services/product/inventory/InventoryStockRequestServices.php
4e8afa6d379a939d053d9aff290c8c65871954358a7b1a182afa8f05f1764437  后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformDocumentRemark.php
d74ad1172af78ed9f08473e623a349666d80732921f8f2f7b0be358a1d022182  后端代码/app/controller/store/product/inventory/InventoryDocumentRemark.php
2467c0d0f43c5b5e671af6850a319265105c64590a1c89041eb48d5e61a7c456  后端代码/route/admin.php
b912b877c24f791614c15b2924a40f6fda9269db89fcd279fcfb8676dfd78322  后端代码/route/store.php
0b5a60fd968c42e03f4215dfb2a78626ab0dd1f6c40e38a6ac784d7f3361cfa4  后端代码/database/upgrades/2026-10-13-库存五类单据备注修订/00-升级说明.md
29972e98abc55fb50a61885d34b1210bb87500276420e90b19f156855a90c340  后端代码/database/upgrades/2026-10-13-库存五类单据备注修订/01-正式升级.sql
9237ec8a959946b0df5b467620dfabe18b434434d5b5098be3a72316906cac3f  tests/inventory/js/document-remark-api-contract.mjs
9d6d4d7bc7fdfc62805c639db349f7c383d838e046ca25ed7087a919fa2af5e5  tests/inventory/js/shared-document-layout-contract.mjs
c57a908758cecef8d5f0a4f6d6d2f79b13a9dad7c2d3c6223ba87c2742c8d281  tests/inventory/php/document-remarks-local-integration.php
dcff4a68235622b0c9003005209c68d3f88a7d564aa3ee8de8e04a6d1a884dae  tests/inventory/evidence/R14-store-usage-remark-2026-10-13.jpg
```

Tracked 补丁指纹（仅本闭环的已跟踪文件）：`c7044f51140a3291ac7ee42fa8a768008d4fcb2d7094f986403c658adecb12b3`。上表包含所有新文件；本记录自身不作递归哈希。

## 注释与文件分组

- 前端源码：`App.vue`、`InventoryBusinessModal.vue`、`InventoryRemarkModal.vue`、`documentDetailReader.js`、`inventoryApi.js`。新按钮、弹窗、明细 ID 与仓库上下文均有边界说明。
- 后端源码：上表的库存服务、两端控制器与两份路由。新增或更新了身份授权、门店会话注入字段、数字传输格式、版本锁、库存事实不可变、整单编辑重基及事实 ID 用途的注释。
- 数据库迁移：本目录 `00-升级说明.md`、`01-正式升级.sql`。
- 自动化测试：本目录上表 3 个 JS/PHP 测试文件。
- 工程脚本：无。
- 生成物：`前端代码/inventory-vue3/dist/` 本地构建产物，不作为源码正本。
- 验收证据：本文件及 `R14-store-usage-remark-2026-10-13.jpg`。
