# R42 铜陵明珠店盘点组织归属修复：本地自测与固定版本

- 基线 HEAD：`7a7358e0ccb7931ca6c26492cc528e004f2f2f0a`
- 状态：开发与本地自测通过，待产品经理对以下固定版本明确验收；未 commit、未部署、未 push。
- 问题：门店 68（铜陵明珠店）默认 STORE 仓库 36 保存的组织路径为旧值 `/110/113/127/160/`，当前组织链路径为 `/110/215/127/160/`；原盘点确认以路径不等直接抛 `inventory_stock_count_location_invalid`，页面仅显示通用库存错误。组织 127 曾移动父级，仓库快照未跟随刷新。

## 范围与边界

- 后端源码：`OrganizationManageServices.php`、`InventoryStockCountServices.php`、`InventoryStoreScopeReconciliationServices.php`、`InventoryErrorMessage.php`。
- 自动化测试：`tests/inventory/php/stock-count-org-scope-contract.php`。
- 前端源码：无。数据库迁移：无。工程脚本：无。生成物：无。
- 验收证据：本文件；不属于业务源码。
- 组织层级移动或门店改绑时，在原事务内更新默认仓库及当前库存的组织维度；盘点确认对旧路径作事务内懒修复。门店、租户、仓库归属或版本不符时继续拒绝，不做跨门店修复；历史库存流水、已确认单据不改写。错误提示改为具体的仓库、库存或组织归属异常。
- 代码注释：上述三个业务服务中的组织移动、现存库存、盘点入口与历史事实边界已补充；错误字典无复杂分支。

## 固定文件 SHA-256

| 文件 | SHA-256 |
|---|---|
| `后端代码/app/services/organization/OrganizationManageServices.php` | `f8c55b199e8fcd77fe485e86d590e70f3bfabf8fa02e1a5f5b2337c937d12daf` |
| `后端代码/app/services/product/inventory/InventoryStockCountServices.php` | `6f8a59b14481725524a52eeb8bb697a686634bac8c359e06c129bf9eb9a793bb` |
| `后端代码/app/services/product/inventory/InventoryStoreScopeReconciliationServices.php` | `eedc4e536d983535d0506cb52c65327717bf2a96718687c8180fe154b7d0f151` |
| `后端代码/app/services/product/inventory/InventoryErrorMessage.php` | `21d3f213b9c4b934a5cf932d37193b4190dd6c5bfac2d46f5aa35ed522691800` |
| `tests/inventory/php/stock-count-org-scope-contract.php` | `13f4bdf06f2a5ae703c415cc08b415ae94a7edbd1aa74159a9932e0090db09b0` |

## 自测结果

- 四个 PHP 源码文件语法检查通过；`stock-count-org-scope-contract.php` 通过；既有 `default-location-scope-reconciliation-contract.php` 4/4 通过；`git diff --check` 通过。
- 本地 `ruihao` 库对仓库 36 修复前只读核对：门店绑定组织 160；仓库是门店 68 的唯一活动默认仓；该仓无当前库存、批次流水、盘点草稿或已完成盘点单。随后仅在本地库将该仓组织路径更正为 `/110/215/127/160/`、版本从 1 变 2，无历史业务事实改动。该本地数据修复不是线上迁移或发布。
- 本地门店 68、员工 565 与真实 SKU 的完成盘点服务调用在外层事务中成功返回新盘点单；外层回滚后核对无测试盘点单、库存或流水残留。测试不在瑞昊线上生成真实业务单据。
- 本地已有库存的门店 133 在事务回滚测试中，旧仓库路径及四条当前库存组织维度可同步修正，数量不变、历史流水不变；回滚后原值恢复。
- 组织 127 的移动路径同步在事务回滚测试中覆盖其下门店，回滚后原值恢复。门店改绑入口已接入相同精确门店修复并由契约测试覆盖；未对真实组织绑定执行变更。

## 发布前提

产品经理验收此固定版本后，才可仅暂存上述源码、测试与本证据文件并本地提交。用户已请求后续瑞昊部署及 push，但仍须先逐实例核对瑞昊目标、备份和回滚点；部署后进行不生成真实盘点单的线上只读验证，验证通过再 push。工作区其余脏文件均不在本闭环内。
