# R39 库存功能权限与单价、金额查看自测

## 范围与口径

- 产品经理确认适用于所有库存功能。门店端按当前库存功能的 V3 岗位使用权限授予该功能内单价、金额查看；不能借盘点权限读取其他库存功能成本。
- 平台端既有库存查看权限内同时可看单价和金额；组织、门店、仓库的数据范围及独立导出、管理权限不扩大。
- 盘点金额仍取已结算批次流水事实；页面只显示，不在浏览器重算。旧独立成本菜单保留作历史权限记录，不再作为库存查看前提。
- 未修改数据库、远程实例、配置或构建产物；当前阶段未提交、未部署、未推送。

## 本地只读验证

- PHP 7.4 对改动的库存后端 PHP 文件逐个 `php -l`：全部通过。
- `store-inventory-policy-contract.php`、`read-model-projection-contract.php`、`platform-operational-query-contract.php`、`unified-query-registration-contract.php`、`operational-unified-query-registration-contract.php`：全部通过。
- `inventory-v3-c3-contract.mjs`：通过。
- 本地数据库只读核对：门店 133 的盘点岗位账号有成本查看能力，其他门店无；同店 11 个有效员工中，8 个没有盘点功能授权，均不能获得盘点成本查看能力。
- 本地已确认盘点单 `PD2609290001` 的门店详情读取：有盘点权限时两行单价均为 330 分，行金额分别为 29040、25410 分；同店无盘点授权的员工对应投影中单价和金额均为空。
- 平台端既有非超级管理员库存查看账号，只读解析结果含查看与成本能力，未扩展其授权门店范围。
- 未重跑会向共用测试库写入夹具的完整库存集成测试；该库既存夹具会导致重复运行数据污染，不能将上述只读验证冒充完整业务验收。

## 固定版本

- 基线 HEAD：`5d7ded5f83daf5d476a8fb0c2b6b4c150b096abd`。
- 后端源码：`InventoryStoreAccessPolicy.php`、`InventoryPlatformAccessPolicy.php`、`InventoryStoreUnifiedQueryContextFactory.php`，以及 10 个门店库存查询控制器。权限边界注释已补充在策略、统一查询映射和盘点/首页入口。
- 前端源码：本权限闭环无新改动；本轮先前未提交的盘点详情金额列仍在 `InventoryBusinessModal.vue`。
- 数据库迁移：无。自动化测试：本文件下列 PHP 合同测试及既有 JS 合同测试。工程脚本/生成物：无。验收证据：本文件及先前的盘点金额自测记录。

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/product/inventory/InventoryStoreAccessPolicy.php` | `0b68478a5013950e1ebe378dbca421deb39b4ec363ef32cd71b02edeade005e0` |
| `后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php` | `6c8e00a27e1e89ac8c721a7e60b379769fc84ee1de3890eaf314db50ec3b83e8` |
| `后端代码/app/services/product/inventory/query/InventoryStoreUnifiedQueryContextFactory.php` | `6f5e1787bcefef3d8b2aeff739e3683e64c83b9708d76494d116b30a4e2f8d14` |
| `后端代码/app/controller/store/product/inventory/InventoryBatchStockQuery.php` | `fee4ac161387ede8801ed274cff1f70c156a7f306fe164d0bfed69db19ab9830` |
| `后端代码/app/controller/store/product/inventory/InventoryBatchTransfer.php` | `57f33f42be645fa35371408614bc5359408a9aaf0c0b3a8bcd33a421fb4475a4` |
| `后端代码/app/controller/store/product/inventory/InventoryCrossSubjectTransfer.php` | `159fa02d10facaa5552a48092e200cef7c39860082ce8a7ad441528f1c70bed2` |
| `后端代码/app/controller/store/product/inventory/InventoryManualInbound.php` | `6bf38bbbed8801948c41458ed267c1c27cc736a74d1493a346a7f73243898a8f` |
| `后端代码/app/controller/store/product/inventory/InventoryManualOutbound.php` | `329bca11af836f0c58805f768ed2687e8016131b052fbf1536b6754e85a6c1fd` |
| `后端代码/app/controller/store/product/inventory/InventoryMovementAnalytics.php` | `cbc33b35ae40dd64ebcfbafc1b0cbff7469a1e862c9b5a9a1bae9a65104e9c6a` |
| `后端代码/app/controller/store/product/inventory/InventoryMovementQuery.php` | `3d4123181ab1ad07023095bf1462de83a47279b18bc142b438be1aa58f5e4e04` |
| `后端代码/app/controller/store/product/inventory/InventoryStockCountQuery.php` | `6e40b117d450f6a326eeeaa4563d6762e158e9eaf3415b2b238afb4b33f7871f` |
| `后端代码/app/controller/store/product/inventory/InventoryStockRequestQuery.php` | `318601bcb358eb23a6ce7256cc52e479d1ac958128b934056fb85d0202877bcc` |
| `后端代码/app/controller/store/product/inventory/InventoryStoreReadModel.php` | `c3e8dc43aeae1603f89df37a3da893c785aee8c1a36955065fb58d4579a91018` |
| `tests/inventory/php/store-inventory-policy-contract.php` | `4ac6c0de65cf71d28180e6c24dec6909eda6314cc5c23ba66e74ac66a21610f6` |
| `tests/inventory/php/read-model-projection-contract.php` | `7008a53fb526db85bac2b92cddb7df2527444e3e73b8f7d80a3f800697909de6` |
| `tests/inventory/php/platform-operational-query-contract.php` | `5d8cb777b92a668b44311435c8348faf711c4d4a520453579b4cf502a07e30f3` |
| `tests/inventory/php/unified-query-registration-contract.php` | `224a1ffc06316c146e73f7b35883e5873805ae5f4db2e4b08c506bcde2771075` |
| `tests/inventory/php/unified-query-command-integration.php` | `874a36f760174284529bc36acae3753fa214d2f6695dc442b0764fc333a615d6` |

本闭环开发与自测完成，待产品经理对以上固定版本确认验收；未获得提交、部署或推送授权。
