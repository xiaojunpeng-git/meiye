# R31 调拨保存成功后列表刷新故障：本地固定版本

- 基线 HEAD：`761a3f299c9f39dea43d54729fa69efeb3113292`。本记录仅针对下列四个文件；共享工作区其他未提交改动不在验收范围。
- 业务事实：`DB2610160136` 的批量保存并发货 POST 返回 200；随后的调拨列表 GET 在服务重启窗口返回 502 HTML。前端把 GET 解析失败当成整次操作失败并清空结果；并非重复调拨或未发货。
- 行为：平台调拨列表 GET 的 502/503/504 最多重试两次，非网关错误不重试，任何 POST 不自动重试。服务端已确认保存后若列表仍失败，保留成功回执和上次列表、明确展示刷新失败原因，旧表不可操作；再次查询成功后恢复。单张单据成功提示显示服务端返回的调拨单号。批量调拨 POST 本身若遇到网关错误或网络中断，提示“结果尚未确认，先查单据”，不冒充事务回滚。
- 前端源码：`前端代码/inventory-vue3/src/App.vue` SHA-256 `954cae6d4bc0897d6371d5c0fa502863200b841324c7b40e3cc752e4da57c51c`；`前端代码/inventory-vue3/src/components/InventoryTransferDraftComposer.vue` SHA-256 `2e94957c6dbfc990f8cce6661cf73a7a2a83f45a26f59953323649c943e8572a`；`前端代码/inventory-vue3/src/services/inventoryApi.js` SHA-256 `11784c610b5b7f195a6a7dac1360731ffb9d8f04239ea48fe8e49836ffc20ede`。
- 自动化测试：`tests/inventory/js/transfer-list-gateway-recovery-contract.mjs` SHA-256 `ce030af3203ee921788882ec68cfc86a9efddeeb485b47d1b21191b17a7fe4aa`。覆盖 502/503 后成功、持续 502、403 不重试、POST 502/断连不重发及结果未确认提示；该测试通过。既有 `transfer-confirmation-contract.mjs`（4 项）、`stock-transfer-form-contract.mjs`（6 项）和 `transfer-worksheet-contract.mjs` 同样通过。
- 构建：`前端代码/inventory-vue3/` 执行 `npm run build` 通过；`git diff --check` 通过。生成的 `dist/` 不纳入源码清单。
- 页面只读核对：`http://127.0.0.1:18081/admin/stock/transfer` 内嵌 `18086/view_inventory_v3/`，列表与操作控件正常；18086 热更新源码含本轮状态标记。未执行真实调拨或线上写操作。
- 已知测试缺口：既有 `tests/inventory/js/inventory-api-contract.mjs` 在本轮断言之前因旧调用 `api.cancelCrossTransfer is not a function` 中止；本轮未改该文件。未在真实服务重启窗口执行端到端保存测试。
- 分类：后端源码、数据库迁移、工程脚本均无本轮修改；本地仅生成构建产物。产品经理尚未对上述固定版本确认验收，未提交、未部署、未推送。
