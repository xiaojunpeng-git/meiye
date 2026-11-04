# 第 1 轮库存首页总部仓权限：统一候选与防回退

- 发布基线 HEAD：`51eb0f80354ed808024fa63a62cfe448886ba0ef`。三站现网库存入口均为 `index-75c55365.js` 与 `index-d96e3ae9.css`，且后端相关 3 文件与该基线一致。
- 防回退前置：`25f62164e` 曾把已验收、已上线的库存单据复制实现从库存源码树移除。以已验收提交 `7cdfbf429ed28f4ceb70a70256e53954615a8188` 的 4 个库存前端文件恢复该功能，单独形成基线提交；不从服务器反向维护源码。
- 本轮库存首页改动在恢复基线之后单独提交。普通账号须有库存首页菜单且员工数据权限覆盖集团全部有效业务门店才可查看集团总部仓指标和仓库选项；内置 `admin` 保留。受限账号的后端指标请求拒绝，前端显示明确空状态并清理旧指标。其他库存列表与单据权限仍按原有服务判断。
- 产品经理已验收的最初候选库存构建包为主 JS `index-05be7c0a.js` SHA-256 `bba16d58bd1a5ce76cb3f41e459f01e229915fefa5439c79fb66e12ec0f826a8`、CSS `index-833c30c4.css` SHA-256 `833c30c44508c4412c7207aa4cd30dbf6816f151cf9a35f37cd2f233b145b547`。从恢复已上线复制功能后的唯一 Git 树重新构建，所得两资源逐字节相同；ExcelJS 懒加载资源亦与现网相同。随后验收的隔离候选 `2aef49ef1d66` 缺少现网复制功能，不用于发布。
- 自测：本地权限事务测试 `PLATFORM_INVENTORY_DASHBOARD_SCOPE_OK`；PHP 文件语法、`git diff --check` 与库存 Vue 3 构建均通过。内置 `admin` 的 `18081` 首页仍显示总部仓选项和指标。三站发布前还须逐站备份、重启常驻 Swoole、核对公网资源及业务读路径。
- 本轮无数据库迁移或配置变更。总部仓库存事实按仓库记录，不能按受限账号的部分门店拆分；本轮只约束库存首页，不触碰既有单据事实、作废、收银或其他实例。

## 文件分组与 SHA-256

| 分组 | 路径 | SHA-256 |
| --- | --- | --- |
| 前置已上线基线 | `前端代码/inventory-vue3/src/components/InventoryBusinessModal.vue` | `cafa72c4dd4c49aef79f1e4f7ac6462ed7aa2fa042d7df9e98972b08a484d165` |
| 前置已上线基线 | `前端代码/inventory-vue3/src/documentCopyDraft.js` | `1c87e50fdbf5e13bf0de6c103a098feefbb2d7a3c090356017b996fe986da87e` |
| 前置已上线基线 | `前端代码/inventory-vue3/src/requestReopenDraft.js` | `93208fd6bcd3d2554ef1aa8dc4076cf336a13aa090ffe20260abb5c46a1d3d58` |
| 前端源码（基线加本轮） | `前端代码/inventory-vue3/src/App.vue` | `ad43162d3895d073f78c517463c95b520accb8003826fb9ed0663cba80fb525d` |
| 前端源码（本轮） | `前端代码/inventory-vue3/src/styles.css` | `3f64ab8ef2fffaa7ce836b66d319a81eb9997759677ee42c9e3dd515617bfd6a` |
| 后端源码（本轮） | `后端代码/app/services/product/inventory/InventoryPlatformAccessPolicy.php` | `fa846a7c606373cfdb0f017d08d92a4fd3fb576e4f16d1a17832f0ace965f377` |
| 后端源码（本轮） | `后端代码/app/services/product/inventory/InventoryPlatformWarehouseServices.php` | `68ea9b91a9f898ff87953c2af927829f3e09ccd9207acc5feffe2de94dcf2002` |
| 后端源码（本轮） | `后端代码/app/controller/admin/v1/product/inventory/InventoryPlatformWarehouse.php` | `d12720d9ce9941a9f557e26863fa9e602e27d8f55e4258cdb9093d895dc8f096` |
| 自动化测试（本轮） | `tests/inventory/php/platform-inventory-dashboard-scope-local.php` | `6d79747a44486df084601d19545f844d6917bd3aa7c7d0bcbe0e4c131d12e0eb` |

业务注释更新于 `App.vue`、`styles.css` 及 3 个后端文件。生成物只作为发布包，不纳入源码提交；验收证据为本文件与既有复制功能验收记录。

## 瑞昊上线复测与补修（待产品经理对新固定版本补验）

- 首次切换 `3fb069c6b23c3392f69d4317174cebdf8e12f567` 后，公网库存入口、JS、CSS 与候选逐字节一致；受限账号实际首页却仍显示总部仓下拉与空指标卡，下方出现“当前权限范围无可查看的总部仓”接口错误。该现象证明仓库目录返回的初步能力与指标接口的最终拒绝不一致，未达到本轮目标。
- 已停止推送与后续站点部署，并从瑞昊独立备份 `rh-server:/root/codex-release-backups/20261105_R1_HQ_3fb069c6b/rh/before.tar` 恢复原 3 个 PHP 文件及库存入口，重启 Swoole。恢复后公网入口 SHA-256 为原值 `34017ad213f762766b079d63ac2d42cbb7187de140db819c44300e480b28ba0a`，HTTP 200；008、010 未切换。
- 补修：仓库目录能力取同一次已解析的服务端身份；前端收到指标接口 `INVENTORY_PLATFORM_ACCESS_DENIED` 时，以最终拒绝为准，清除总部仓清单、选择与旧指标，展示无权限说明。只影响首页，不更改单据权限。
- 本地浏览器用固定构建包模拟“仓库目录称可见、指标接口明确拒绝”的不一致响应：最终只显示“当前权限范围无可查看的总部仓”，仓库下拉和指标卡均不存在；模拟全范围允许时，仓库下拉和真实指标卡仍显示。固定构建主 JS `index-ece30b6c.js` SHA-256 `fe85b90daed041a8055f8541432fa1b7f097b4698d7710c9a55703eae4d9e447`，CSS仍为 `index-833c30c4.css`；入口 SHA-256 `2f989c88b7f159e0ea11e7328a7de722c9f9c6fe758b5c1b6ac742cd6567e446`。PHP语法、库存 Vue3 构建、`git diff --check`、单据复制测试与权限事务测试通过。
- 补修更改了已验收候选源码与主 JS，依照 `AGENTS.md` 须取得产品经理对本固定补修候选的补验，再提交和部署。
