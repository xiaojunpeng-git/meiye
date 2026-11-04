# 第 1 轮库存首页总部仓权限：统一候选与防回退

- 发布基线 HEAD：`51eb0f80354ed808024fa63a62cfe448886ba0ef`。三站现网库存入口均为 `index-75c55365.js` 与 `index-d96e3ae9.css`，且后端相关 3 文件与该基线一致。
- 防回退前置：`25f62164e` 曾把已验收、已上线的库存单据复制实现从库存源码树移除。以已验收提交 `7cdfbf429ed28f4ceb70a70256e53954615a8188` 的 4 个库存前端文件恢复该功能，单独形成基线提交；不从服务器反向维护源码。
- 本轮库存首页改动在恢复基线之后单独提交。普通账号须有库存首页菜单且员工数据权限覆盖集团全部有效业务门店才可查看集团总部仓指标和仓库选项；内置 `admin` 保留。受限账号的后端指标请求拒绝，前端显示明确空状态并清理旧指标。其他库存列表与单据权限仍按原有服务判断。
- 产品经理已验收的最初候选库存构建包为主 JS `index-05be7c0a.js` SHA-256 `bba16d58bd1a5ce76cb3f41e459f01e229915fefa5439c79fb66e12ec0f826a8`、CSS `index-833c30c4.css` SHA-256 `833c30c44508c4412c7207aa4cd30dbf6816f151cf9a35f37cd2f233b145b547`。从恢复已上线复制功能后的唯一 Git 树重新构建，所得两资源逐字节相同；ExcelJS 懒加载资源亦与现网相同。后端 3 文件与该候选已验收指纹相同。随后验收的隔离候选 `2aef49ef1d66` 缺少现网复制功能，不用于发布。
- 自测：本地权限事务测试 `PLATFORM_INVENTORY_DASHBOARD_SCOPE_OK`；PHP 文件语法、`git diff --check` 与库存 Vue 3 构建均通过。内置 `admin` 的 `18081` 首页仍显示总部仓选项和指标。三站发布前还须逐站备份、重启常驻 Swoole、核对公网资源及业务读路径。
- 本轮无数据库迁移或配置变更。总部仓库存事实按仓库记录，不能按受限账号的部分门店拆分；本轮只约束库存首页，不触碰既有单据事实、作废、收银或其他实例。

## 文件分组与 SHA-256

| 分组 | 路径 | SHA-256 |
| --- | --- | --- |
| 前置已上线基线 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `cafa72c4dd4c49aef79f1e4f7ac6462ed7aa2fa042d7df9e98972b08a484d165` |
| 前置已上线基线 | `前端代码/inventory-vue3/src/documentCopyDraft.js` | `1c87e50fdbf5e13bf0de6c103a098feefbb2d7a3c090356017b996fe986da87e` |
| 前置已上线基线 | `前端代码/inventory-vue3/src/requestReopenDraft.js` | `93208fd6bcd3d2554ef1aa8dc4076cf336a13aa090ffe20260abb5c46a1d3d58` |
| 前端源码（基线加本轮） | `前端代码/inventory-vue3/src/App.vue` | `f71be5935903294c6b72c4f89e36a3334d699858ab2fbc771f1ac11d1483ab06` |
| 前端源码（本轮） | `前端代码/inventory-vue3/src/styles.css` | `3f64ab8ef2fffaa7ce836b66d319a81eb9997759677ee42c9e3dd515617bfd6a` |
| 后端源码（本轮） | `后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php` | `fa846a7c606373cfdb0f017d08d92a4fd3fb576e4f16d1a17832f0ace965f377` |
| 后端源码（本轮） | `后端代码/app/services/product/inventory/InventoryPlatformWarehouseServices.php` | `68ea9b91a9f898ff87953c2af927829f3e09ccd9207acc5feffe2de94dcf2002` |
| 后端源码（本轮） | `后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php` | `fe3e22f3d075fc045113f007f10587ee5e2cde4b69856eeb3a0f5c8ee01a25ba` |
| 自动化测试（本轮） | `tests/inventory/php/platform-inventory-dashboard-scope-local.php` | `6d79747a44486df084601d19545f844d6917bd3aa7c7d0bcbe0e4c131d12e0eb` |

业务注释更新于 `App.vue`、`styles.css` 及 3 个后端文件。生成物只作为发布包，不纳入源码提交；验收证据为本文件与既有复制功能验收记录。
