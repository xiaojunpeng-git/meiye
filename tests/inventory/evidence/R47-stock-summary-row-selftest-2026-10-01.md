# R47 库存查询合计行自测记录（2026-10-01）

## 固定验收版本

- 基线 HEAD：`d2a9d498688872109867eaa2f78c85d7a841d15b`
- 产品经理验收：2026-10-01 明确反馈“我测试没有问题”，确认本固定版本验收通过，并授权 commit、部署瑞昊、push。
- 范围：库存查询表头下新增首行“合计”，展示完整筛选结果的可用库存与库存金额合计。
- 权威数据源：已结算的库存批次事实；门店与平台总部读模型继续复用既有库存权限范围。
- 事实粒度：商品 / SKU 汇总；筛选后、分页前计算合计。
- 统计时点：接口读取时点；批次统一查询继续遵守其查询截止日。
- 金额口径：服务端整数分累计，页面按统一报表规则四舍五入为整数元；无成本权限时返回并展示 `-`。
- 写入、迁移、并发与幂等：本闭环为只读查询和展示变更，不新增写入或数据库迁移。

## 真实本地数据验证

在本地 RH 数据库门店 `1（合肥世纪中心店）` 调用 `InventoryStoreReadModelServices::productSummary`：

- 完整筛选结果 12 条，以每页 5 条分别读取第 1、2 页，两个分页返回的合计完全一致。
- 完整结果逐行独立复算：可用库存 `1332`，库存金额 `14785200` 分；与接口 `stockSummary` 完全一致。
- 无成本权限时库存金额合计为 `null`，前端展示 `-`。
- 关键字“纸”筛选后结果为 0 条、数量与金额均为 0，证明合计跟随筛选而不是沿用未筛选结果。
- 后端明确返回 `tableLayout.fixed=true`、`summaryRow=true`。

## 真人页面回归

- 首次反馈未显示的原因不是汇总接口缺失，而是 `18091` 收银热更新容器仍缓存了修改前的共享库存组件。
- 重启 `mohe-cashier-v3-src` 后，在用户当前 `18091` 标签页重新进入“库存 → 库存”，表头下第一行已显示：`合计 / - / 1332 / ¥147852 / -`。
- 页面明细仍为 12 条，每条 111，合计数量与接口及完整结果复算一致。

## 自动化与构建

- `php tests/inventory/php/stock-summary-total-contract.php`：8/8 通过。
- `php tests/inventory/php/batch-query-contract.php`：23/23 通过。
- `php tests/inventory/php/product-summary-quantity-range-contract.php`：4/4 通过。
- `node tests/inventory/js/stock-summary-row-contract.mjs`：5/5 通过。
- `node tests/inventory/js/inventory-stock-list-scroll-contract.mjs`：5/5 通过。
- 本闭环 7 个 PHP 业务文件语法检查通过。
- `npm run build`（`前端代码/inventory-vue3`）：通过，1818 modules transformed。
- `npm run build`（`前端代码/cashier-v3`）：通过，1983 modules transformed。
- `git diff --check`：通过。
- 本地服务重启后：`18092`、`18091` 均返回 HTTP 200。

## 闭环文件与 SHA-256

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/inventory-vue3/src/App.vue` | `01d01ba227bbec0326ea02eaea2e28ec540021ca369593eb1faedcc797da4aa2` |
| 前端源码 | `前端代码/inventory-vue3/src/styles.css` | `389e0af4fa6263e5f8c37436aff2c1923cdfe268e14f0d58e41197fbe238a968` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStockSummaryTotalsServices.php` | `c9d4fbef63c10afd9d61941268f0f7660564f0a6ecc75d4bcd1e959fb81bb1f7` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryPlatformHqReadModelServices.php` | `9e5c9d218f3471b72eeb69581e60e7af953a33947d972ae333fbd047f91601da` |
| 后端源码 | `后端代码/app/services/product/inventory/InventoryStoreReadModelServices.php` | `f5241c2c8886166c6ca718776ba8acbc2043b05c75fc45ada5a30fbb3c142347` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryBatchStockQueryContract.php` | `e3b24e5fb4536052e5b207509160e6e6cc6679b44d5bfb80ca7cfe0008c44336` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryBatchStockQueryProvider.php` | `42fda40d4a9d6276b4c818340c04673597a76dee3c695680d849405126165df7` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryBatchStockUnifiedQueryProvider.php` | `859b45546a3b77c37f00e8a8af5cba515d455c7d02625c41659e40386f9fe806` |
| 后端源码 | `后端代码/app/services/product/inventory/query/InventoryProductStockSummaryServices.php` | `986f7171e6906a1e20c00dea3eb40f7a17af96bd604f1d922856acb1cb41bafe` |
| 自动化测试 | `tests/inventory/php/stock-summary-total-contract.php` | `2f7469e9f383b2fca31db905853386d1d569414bc164805b3719e6e232ef9574` |
| 自动化测试 | `tests/inventory/js/stock-summary-row-contract.mjs` | `7563c2af26da9a44f99341a642b92bbf20cc436a00874d13e9bac730f55c53e1` |
| 自动化测试 | `tests/inventory/js/inventory-stock-list-scroll-contract.mjs` | `f37886e0a1285159eb29cc4fdfc5e4c3548cf0878b000e09fe1ebd0dfa0c9c90` |

## 注释更新

本轮在门店 / 总部读模型、统一查询汇总字段与强制汇总注入、前端服务端合计消费和金额展示处补充了职责、权限及“筛选后分页前汇总”的关键约束注释。

## 状态

开发、自测与产品经理验收均已完成；进入精确文件提交、瑞昊单实例部署及线上验证。
