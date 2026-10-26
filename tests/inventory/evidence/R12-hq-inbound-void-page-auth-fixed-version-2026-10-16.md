# R12 总部入库作废页面授权固定版本（2026-10-16）

## 范围与基线

- 需求：产品经理确认“有功能入口，就有作废权限”；本闭环只覆盖平台“入库管理”的总部入库单作废，不推广到出库、盘点、调拨及通用仓库管理。
- 源码正本：`美容源码/`；基线 HEAD：`617f9ca410810977a0c102ad44e041d16ad56260`。工作区存在其他任务改动，本闭环仅列出下方 5 个文件。
- 前端源码：无修改。现有库存 Vue 3 列表按服务端 `can_void` 显示“作废”按钮。
- 后端源码：`InventoryPlatformAccessPolicy.php`、`InventoryPlatformHqInboundServices.php`、`InventoryHqLocationServices.php`。
- 自动化测试：`r9-platform-transfer-menu-access.php`、`platform-document-detail-scope-contract.php`。
- 数据库迁移、工程脚本、生成物：无。此文件仅为验收证据，不是业务源码。

## 固定文件 SHA-256

| 文件（相对源码正本） | SHA-256 |
| --- | --- |
| `后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php` | `cceedefabc7a0b7d1275402dcd07594446e33b5ee5e9b69ad66e11253e07ef2c` |
| `后端代码/app/services/product/inventory/InventoryPlatformHqInboundServices.php` | `cfb421599001cf8ddbd2a6abbd2e3011836b70ba4fc21622217c04e4934f6c1d` |
| `后端代码/app/services/product/inventory/InventoryHqLocationServices.php` | `fd55c0d9144fae63d1be16f044a27b3c11a42991965e3131c72c3cf0317222f7` |
| `tests/inventory/php/r9-platform-transfer-menu-access.php` | `d0c58e33fe1ecbd8079a3332f9853fb14a2f6e7066b4a1b8a7266e3633129bc6` |
| `tests/inventory/php/platform-document-detail-scope-contract.php` | `cfdcb24a4a1f3ee38390a34cadaa32602e00d22c686089eba7380bd37d79d339` |

上述 5 文件的 `git diff --binary` SHA-256：`696751241182c8441ef5e9809d0be075cb7a430ad2fdaa97b969cedb2dd1506f`。证据文件不计入此补丁指纹。

## 业务与权限核对

- 瑞昊只读前检：账号 `A18156013263` 的有效角色有旧入库页面入口及总部仓库管理，但缺独立原生库存查看权限；旧代码因此隐藏 `can_void`。生产菜单也未登记该入库作废 POST；单纯显示按钮无法通过接口权限。
- 新规则：有效入库页面入口可通过精确 `POST product/inventory/v3/hq/inbound/:id/void`，且列表／详情同步投影作废能力。既有原生仓库写授权继续兼容。
- 领域命令再次解析当前账号、入库页面授权及可读总部仓；冲销事务锁定仓库，检查启用状态、租户、原单据、批次库存、账实一致及幂等键。撤销页面入口后立即失去作废能力。
- 瑞昊 `RK2610110001` 尚未作废，但原始 6 个批次中有 3 个批次的当前可用量不足以冲回原入库量。权限修复后可看到作废入口；对这张单执行作废会返回“原入库批次剩余库存不足，不能作废”，不会改变库存。未对生产单据执行作废。

## 自测结果

- 5 个修改文件 `php -l` 全部通过，`git diff --check` 通过。
- 本地 MySQL 事务回滚测试 `r9-platform-transfer-menu-access.php` 通过：仅入库页面可见已完成入库单作废按钮并通过精确 POST；无入库页面拒绝；撤销角色规则后接口和领域服务均拒绝；未知命令及其他库存写命令拒绝；原有建单、调拨等权限回归通过；合成角色和单据回滚。
- `platform-document-detail-scope-contract.php` 通过：总部与门店详情读取范围、成本遮盖及门店详情不获总部作废能力。
- `manual-document-reversal-contract.php` 通过：批次库存不足及账实不符仍阻止冲销，审计和幂等行为不变。
- 修改业务注释的文件：上述 3 个后端源码文件；测试夹具同步适配新的授权契约。

## 交付状态

开发与自测完成，待产品经理对本固定版本明确确认验收。本轮尚未本地提交、构建、部署、远程改库或 push；生产实例仍运行原版本。
