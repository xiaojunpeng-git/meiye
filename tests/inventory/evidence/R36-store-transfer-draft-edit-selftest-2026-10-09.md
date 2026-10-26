# R36 门店调拨草稿编辑自测

- 本轮固定时工作区 HEAD：`5d7d5cb0d5a960396add7f0cbaa0a53a08bed789`。期间其他任务推进了 HEAD；本闭环只记录下列文件，未合并或处理其他工作区改动。
- 产品确认范围：草稿可修改调拨日期、调入方、商品及数量、备注；调出方不变，关联请货单时调入方与请货关系不变。
- 保存问题复现：霍山文峰店选店会话的“李倩”是组织员工（本地员工记录 490），不在该店 `system_store_staff` 表中；旧调拨写入只按后者验操作者，返回未映射的 `inventory_cross_transfer_scope_denied`，页面显示笼统失败。产品进一步确认：能进入调拨功能就能操作，包含既有“门店查看模式”；此例外只适用于调拨，不改变其他页面的只读规则。
- 页面范围：门店端调拨列表的草稿、且当前门店为调出方时显示“编辑”；打开最新详情回填。平台端与已发货、已收货单据不开放本轮编辑。
- 状态：开发与自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push。

## 文件清单

- 前端源码：`前端代码/inventory-vue3/src/App.vue`、`前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue`、`前端代码/inventory-vue3/src/services/inventoryApi.js`。
- 后端源码：`后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php`、`后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php`、`后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php`、`后端代码/app/services/product/inventory/InventoryStoreAccessPolicy.php`、`后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php`、`后端代码/route/store.php`。
- 自动化测试：`tests/inventory/js/cross-transfer-draft-edit-contract.mjs`、`tests/inventory/php/cross-transfer-draft-edit-contract.php`、`tests/inventory/php/cross-transfer-draft-edit-integration.php`。
- 数据库迁移、工程脚本：无。构建生成物 `dist/` 不入源码提交；本记录是验收证据。
- 更新的业务注释：`App.vue`、`InventoryBusinessModal.vue`、`InventoryCrossSubjectTransfer.php`、`StoreCkeckRoleMiddleware.php`、`InventoryCrossSubjectTransferServices.php`、`InventoryStoreAccessPolicy.php`。

## 数据架构自检

- 权威源是 `inventory_cross_transfer_document` 与其草稿明细 `inventory_cross_transfer_line`，最小粒度为一张调拨单的一条请求商品行。只允许 `DRAFT` 且当前门店是原调出方时替换草稿；正式发货仍沿用既有批次分配、库存扣减和事实写入路径。
- 编辑在数据库事务内锁定调拨单与原明细，用详情返回的内容令牌拒绝旧页面覆盖并发更改；发货若先取得锁，编辑会因状态改变而拒绝。旧行替换失败会回滚，不留下半张单。令牌仅作版本比较，不能代替服务端权限。
- 调出方、单号、原创建操作时间和创建幂等键不改；关联请货单 ID、请求行映射及调入方不改，并在保存时重新校验请货状态和商品映射。可改业务日期，但不伪造原操作时间；调入方/商品快照跟随本次草稿修改。
- 草稿编辑不产生或冲销库存批次、出入库事实、履约事实或经营指标；发货、收货仍在原成功终态记账。列表和详情的 `can_edit` 都由服务端按当前门店与状态给出，更新接口再次独立校验，不依赖按钮显隐。
- 没有新增表、索引、报表读源或跨店数据范围。只在打开草稿时读取权威详情；分页查询与正常数据权限不变。隔离库事务测试检查保存前后单号、明细及事实零变化，并验证回滚。
- 操作者由认证会话解析：普通门店员工沿用既有身份；组织员工和查看模式仅在调拨服务中使用真实员工 ID 与名称快照，写入当前令牌选店。查看模式的例外只放行新建、编辑、发货、收货四类调拨 POST；其他库存写路由、取消与作废不放行。调拨单按既有状态约束发货与收货，库存移动事实继续保存操作者类型、ID 和名称。
- 新建草稿在服务端重新校验调出方与调入方属于同一组织根；不能依赖前端候选列表阻止伪造跨组织写请求。草稿失败不产生库存事实，来源与目标 SKU 在保存时重新验证。

## 验证

- `php tests/inventory/php/cross-transfer-draft-edit-contract.php`：9/9 通过。
- `node tests/inventory/js/cross-transfer-draft-edit-contract.mjs`：5/5 通过。
- `tests/inventory/php/cross-transfer-draft-edit-integration.php`：在现有 Swoole 容器中、仅连接 `inventory_manual_inbound_test_20260730` 隔离库，17/17 通过；覆盖组织员工及查看模式保存/编辑、真实员工审计、跨门店令牌和跨组织调入方拒绝、其余只读或无岗位权限拒绝、四种调拨写路由白名单，以及原草稿并发/状态/库存事实回归。事务回滚后本次测试调拨单为 0。隔离库的旧最小夹具缺少商品 `pid`、调拨人/操作人快照列和员工表，测试仅在该隔离库按需补齐；未改正式库。
- `node tests/inventory/js/stock-transfer-form-contract.mjs`：6/6 通过。
- `npm run build`（`inventory-vue3` 与嵌入它的 `cashier-v3`）：均通过，保留既有大 chunk 警告；`php -l`、`git diff --check` 通过。
- `php tests/inventory/php/cross-subject-transfer-contract.php`：12/13 通过；失败项是本轮未修改的总部选品与入库历史源码断言，不能宣称旧套件全绿。
- 独立浏览器标签因无登录态停在登录页；没有执行真实页面交互或线上写入。产品经理需在已有登录态的本地门店端验证按钮、表单回填和保存。
- 只读核对本地 `ruihao`：李倩是有效组织员工且具有调拨功能授权；截图所选商品在霍山文峰店与政和朝阳店有匹配规格，两个门店属于同一组织根。后端本地 `APP_DEBUG=true` 的 Swoole 热更新已重载修改后的 worker；未向该业务库写入测试调拨。

## 固定文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/inventory-vue3/src/App.vue` | `b65e269a847576da618c6ad4c91f0db8e9f48457882f8a9906eeaeaed22e2661` |
| `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `25a8efe59f48b45959b0316b9d91e86793424c5a63f76e2c2a9a5ce3b9c32d25` |
| `前端代码/inventory-vue3/src/services/inventoryApi.js` | `e19e6d279d05acd84b050fcf1ca005e965a21d4415cb4761c93b35e7ebb2828a` |
| `后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php` | `53beadcc756468fa1a38c31b159eb20613965d8912f2a959be5f45276f1ba085` |
| `后端代码/app/http/middleware/store/StoreCkeckRoleMiddleware.php` | `986f000c5e14a05ce09fbdef21a76ceb9c6265f79c80b36c97c569f01e5e3ca8` |
| `后端代码/app/services/product/inventory/InventoryCrossSubjectTransferServices.php` | `ca648af872dd2e8d8f88e1b681e627de8edae5904164316372782ba0c29b06db` |
| `后端代码/app/services/product/inventory/InventoryStoreAccessPolicy.php` | `bcb395d72eaa364ad80ccdc33201f40a342f6de1c9e6eefc789da92b75c62eff` |
| `后端代码/app/services/product/inventory/query/InventoryOperationalUnifiedQueryProvider.php` | `387c3a9a0f5b2ecc08021fdac0cbd98042f17c752455f00c9eab8c46fd758b64` |
| `后端代码/route/store.php` | `d5040f853bd9d9a6bb3fb3d5dc9fc96820218b56ba152330228f1c8614fef3cb` |
| `tests/inventory/js/cross-transfer-draft-edit-contract.mjs` | `54bb710e14078dde198c3bf08537d33760973ac71f496743f0e8ae92efbb2efe` |
| `tests/inventory/php/cross-transfer-draft-edit-contract.php` | `8cb95f86310b2eb97f4e9113c7d860c65780c8e2a090729be8259c59577414db` |
| `tests/inventory/php/cross-transfer-draft-edit-integration.php` | `d2c10905c76505e1302f891fca8e189bd56fb098100865e45eb2f3018e0907ad` |

- 九个已跟踪源码文件的补丁指纹 SHA-256：`6870a03bc9aec8117f5e7c4e4ee55793fc039d6b9254ad7b2ec4019c1abb1b48`。
