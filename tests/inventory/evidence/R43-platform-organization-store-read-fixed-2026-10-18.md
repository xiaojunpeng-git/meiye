# R43 平台库存单店查看固定候选

- 基线 HEAD：`3a16b1910045dfea1c6c6e1038a0d0a12e583a0c`。其他任务工作区改动保持原样；本轮文件与开发期间新增 HEAD 无重叠。
- 范围：平台入库、出库、调拨、请货、院装管理默认保留原总部查询；组织树只用于选一家门店查看，清除后回平台端。单店列表与详情服务端按同一门店默认仓收窄，跨店页面只读；既有总部写命令及其权限不变。
- 数据源：原库存单据、批次流水及统一查询；无迁移、无生产数据修改。
- 文件指纹清单的 SHA-256：`21715d4d6d106142df65ee0ca0e7ce13b8f54df5961e66edc8808b36f8c1f8fa`（以下各行按所列顺序连接再计算）。

| 类型 | 源码正本路径 | SHA-256 |
| --- | --- | --- |
| 前端 | `前端代码/inventory-vue3/src/App.vue` | `3d83d780c814298d79ba55fa9b39efe433c522ef4c8bfb76bb4b89d8784ffc45` |
| 前端 | `前端代码/inventory-vue3/src/components/InventoryOrganizationStorePicker.vue` | `eab80cce437c0e9add23e66fd4f97d29a86e836e6211b882bd6bc29091a64f76` |
| 前端 | `前端代码/inventory-vue3/src/documentDetailReader.js` | `a79ba662dea0aa1c397c610445eccfedc7a59f11daf0d550384d370006e6c31b` |
| 前端 | `前端代码/inventory-vue3/src/services/inventoryApi.js` | `09642096dd4c2578997c864d1d5b2cc6242553665af64e36b7b6826c7845ca1c` |
| 后端 | `后端代码/route/admin.php` | `d06f81d33ad767bb5f32cacc5a3493a67e59249d645a1eb0970ea51a0c921cc3` |
| 后端 | `后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php` | `94ee9c5154c9b391da39dc842d56c1273a6f6625315f044f938a3d556c364e45` |
| 后端 | `后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php` | `d52285106aeb842cc2af444ced7bf675da0b8202ad5102e8b14bf79f7ee259a0` |
| 后端 | `后端代码/app/services/product/inventory/query/InventoryPlatformUnifiedQueryContextFactory.php` | `1793c8a15bffbb587eb7cef2a621948d2f5684b82c04137a4817b3c3f6551310` |
| 后端 | `后端代码/app/services/product/inventory/InventorySalonUsageServices.php` | `c7aa7aa6ad962876232247111cdb3725a286c3ac41246b0d9c913b1735294305` |
| 后端 | `后端代码/app/services/product/inventory/InventoryDocumentRemarkServices.php` | `b2c0a4c90429404ac6fd616265102a5e3b3fee86b9b69601ce29a87c445b8759` |
| 测试 | `tests/inventory/js/platform-organization-store-read-contract.mjs` | `0e5da31764d6b6bcd7eb88aa8190ed5c653a8acd5ae632668513feec058af4e9` |
| 测试 | `tests/inventory/php/platform-organization-store-read-contract.php` | `78fed92f92f93a80bdf275bcb18463cd24d193fbbbb4ed297c427038683a5de8` |
| 测试 | `tests/inventory/php/platform-organization-store-read-local.php` | `e9b5d27866f8eac1b8a96dfac7fe6bd8918f88db69f7262291da5c16c65a77ac` |

## 本地验证

- `node tests/inventory/js/platform-organization-store-read-contract.mjs`：通过。
- `php tests/inventory/php/platform-organization-store-read-contract.php`：通过。
- 本地 Docker PHP + MySQL 事务回滚测试：五页可读同一所选门店，原写范围不变；请货、调拨原单详情可读，跨店备注写入被拒；通过。
- 原平台业务查询、单据详情范围、院装项目选择三项合同测试：通过。
- 六份本轮 PHP 文件语法检查、`git diff --check`：通过。
- `前端代码/inventory-vue3/` 执行 `npm run build`：通过。生成入口 `dist/index.html` SHA-256 `464d5437b10768fac2500b86edfb5e2d60c3cfc3d6408051668eb8c01673d4f3`，主 JS `index-62f20295.js` SHA-256 `b6c69139f4f849afec3da8dff389e80610a8629af733822693771e6279856895`，主 CSS `index-bcb3352c.css` SHA-256 `bcb3352c898195deab9a82dde07192f024b151f65462ab3f81bfa9f339b8cb5f`。构建产物为生成物，不作为开发正本。
- 注释：本轮已更新 `App.vue`、选择控件、单据详情读取、平台权限策略、统一查询上下文、院装范围及备注服务中的关键读取/写入边界说明。

状态：开发及本地自测通过，待产品经理对上述固定候选确认验收；未 commit、未 push、未部署。瑞昊和渼约008线上基线及备份本轮不适用，未连接远程实例。
