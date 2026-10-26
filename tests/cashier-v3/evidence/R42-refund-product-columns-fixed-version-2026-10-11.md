# R42 退款记录商品列本地自测

- 状态：本地实现及可执行的契约/列表自测完成；新增无原单分类写入的真实退款回滚验证受测试账号权限阻挡，完成后再交产品经理确认验收；未 commit、部署、push。
- 源码基线：`dac7aed256a477d375a77726fe71c67c6b5c9e79`；唯一源码为 `美容源码/`。本轮无远程实例操作；目标实例、线上迁移、备份与回滚不适用。
- 范围：退款记录的“退款金额”左侧固定增加“商品分类、商品名称”；退款金额、状态、日期、权限和退款命令口径不变。旧账号列配置也按这一相对顺序显示，列表和导出共用字段合同。

## 数据架构自检

- 权威源：正式整单退款按退款明细快照定位原销售明细分类；单据退款按原销售明细分类；V3 卡项退款按购卡回执定位原销售商品；无原单退款在成功退款事务中冻结目录分类；旧卡退款只读其退款请求中的卡名。
- 粒度与生效：一笔成功退款操作一行，多商品名称/分类去重后按原明细顺序并列。仅投影既有成功/作废状态，`business_date`、`settled_at`、金额与原事实均未改变。
- 事务、幂等与冲销：无原单分类快照写入既有退款请求 JSON，与原退款事务同进退；沿用原幂等键，不新增资金或业绩事实。旧退款不回写，作废链不变。
- 历史与权限：商品名称和分类优先用业务发生时快照；历史缺分类则空值（页面显示“—”），不把“卡项/项目/产品”类型伪装为商品分类，也不回读可变目录。服务端继续按租户和获准门店限制列表与导出。
- 性能与对账：只批量读取当前页整单退款明细；关联使用购卡回执和销售明细唯一键，不新增全表按行查询。退款行数和金额可与原操作记录逐笔核对。

## 文件与指纹

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `511a676b3b2728a1a8aa9263a7abd1753fe3f0ab77d615a1daaf35667a11418b` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `a7c868ce78d4a337d784a2817a76898cdeae809d8659005c66e3169d013249fb` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryContract.php` | `d8cb35ed48705c8ded410930cf6892019108d9336db4efac96b3247467925206` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php` | `3f7953f62f5c7b5986951effa5765c65299fea7140e0ef91a552003ec38aad04` |
| 后端源码 | `后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php` | `b9944280d85e9311501ee0470235b992f81d934d6fe8243e4908d5536c96c76d` |
| 自动化测试 | `tests/cashier-v3/js/r42-refund-product-columns-contract.mjs` | `c7aa785ee34258fdaf0c34152b414fe1c9b779de4d3d88f307440ac1d0101a0c` |
| 自动化测试 | `tests/cashier-v3/php/r42-refund-product-columns-contract.php` | `efcd682c9b9b245745b3470fdb31efb4e9dabe920d900429b1923e3e5ac76f04` |

- 上述源码的工作区补丁 SHA-256：`50dc4a104ad01ede6f91057a45b8b6b11152fc18ae6547cc50a358ae826dce9f`。该补丁包含同工作区待验收的退款归属改动，不能单独当作本项已提交版本。
- 数据库迁移：无。工程脚本：无。本地生成物：`前端代码/cashier-v3/dist/index.html`（SHA-256 `ea865107bfdd78a6fb889dbe6b74b90f4cba0dc8e3486516034df2f1c844b027`）；生成物不作为源码正本。
- 注释更新：`OrderCenterView.vue`（旧列配置位置约束）、`CashierV3OrderCenterRecordQueryServices.php`（历史快照和分页批读）、`CashierV3OrderCenterUnifiedQueryContract.php`（列表/导出口径）、`CashierV3UnifiedRefundServices.php`（无原单分类冻结）。

## 验证

- `node tests/cashier-v3/js/r42-refund-product-columns-contract.mjs`：PASS。
- PHP 7.4 容器内运行 `r42-refund-product-columns-contract.php`：PASS，覆盖多商品、单据、卡项、旧卡、无原单及缺快照。
- PHP 7.4 对四个本轮后端文件做 `php -l`：PASS。
- PHP 7.4 容器内运行 `r42-refund-attribution-rollback.php`：PASS，退款列表实际 SQL 与既有归属投影可读。
- `npm run build`：PASS；以 `前端代码/cashier-v3` 为本地热更新源，未打包 8080 集成包。
- 本地 18091 浏览器：退款表头顺序为“退款内容、商品分类、商品名称、退款金额”；2026-10 旧卡退款显示商品名“瑞美六维”、分类“—”，原退款金额仍为 ¥500。
- `order-center-unified-export-contract.php`：35 PASS / 3 FAIL；失败均为本任务前已有的销售日期/首屏时间静态断言，不涉及新增退款两列。
- `r32-unlinked-catalog-refund-rollback.php`：当前本地测试账号在退款命令入口被权限拦截，无法完成真实退款落账回归；检查该测试原因记录数为 0。不得将本次纯契约测试冒充该动态链路已通过。

## 待产品经理验收

- 核对“商品分类”应与现有补交记录一样指商品档案分类；若业务本意是“卡项/项目/产品”商品类型，须调整取值和重新固定版本。
- 在具备本地退款测试权限的账号下完成无原单新退款的分类快照、列表和导出联调，并确认旧记录缺分类显示“—”可接受。
