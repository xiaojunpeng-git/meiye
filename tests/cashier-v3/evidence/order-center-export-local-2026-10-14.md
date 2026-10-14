# 订单中心消费订单导出本地自测（2026-10-14）

## 固定范围

- 固定时工作区基线 HEAD：`5ffd8408bf107dc05cc9b085b6241c5378aed33b`。产品经理曾确认首版测试通过，随后发现多商品订单的导出列与页面明细不一致；本文件记录修正后的新验收版本，须重新确认。并行任务推进 HEAD 时，提交前须重新核对本表全部暂存指纹。
- 权威来源：订单中心现有销售订单 Reader 的授权只读投影；筛选、排序、权限及订单分页仍由统一查询执行器处理。Excel 在已命中的订单内按页面商品明细展开，整单金额、商品数量和商品摘要只写首条，不按商品自行分摊。
- 前端源码：本闭环无改动。后端源码：`UnifiedQueryExportTaskServices.php`、`CashierV3OrderCenterUnifiedQueryWorkerContextResolver.php`、`AbstractCashierV3OrderCenterUnifiedQueryProvider.php`、`CashierV3OrderCenterQueryExecutionServices.php`、`CashierV3SalesOrderExportRows.php`。
- 数据库迁移：无。自动化测试：`metadata-integration.php`、`order-center-unified-export-contract.php`、`r37-order-center-unified-filters.php`、`order-center-sales-export-field-parity.php`。工程脚本、源码生成物：无。验收证据：本文件及下述本地下载文件。
- 业务边界注释已在五个后端源码文件中补充或更新。Provider 工作区另有其他任务的 `supplement_attribution` 未提交行，不属于本闭环，只能暂存本轮两个差异块。

## 固定文件指纹

以下为本闭环暂存区文件的 SHA-256；Provider 指纹专指排除其他任务差异后的暂存版本。

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/query/UnifiedQueryExportTaskServices.php` | `6ceab1160813998a4a686edba367bdc7b7bb2dd9d69ef553052a6cfa5c7d79e0` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterUnifiedQueryWorkerContextResolver.php` | `a02abc65e4c57a141591b6837706f4822b585043c5d54be7b055998bb5dc019d` |
| `后端代码/app/services/cashier/v3/order/AbstractCashierV3OrderCenterUnifiedQueryProvider.php` | `c16bf3e04f58dd22a686e07d98538481202d25a1b824baefe0d4ed506a07d2c6` |
| `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterQueryExecutionServices.php` | `beb7bbafc7054cd4d12431802edfccafd2ce5a77e11f5b7afb0b40f0627dede0` |
| `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderExportRows.php` | `e8716ec4c86987f630e2b20002b6ce5805d23ed948029ac03af569de24f6e147` |
| `tests/unified-query/php/metadata-integration.php` | `f6e5a8b853697ac79462397cf16885e47534f8f35696228b8410c9888c09acc0` |
| `tests/cashier-v3/php/order-center-unified-export-contract.php` | `e8781f31dee740fd9ed7264c4f0413fa3adabcc274345a32cfdfd21e129582b1` |
| `tests/cashier-v3/php/r37-order-center-unified-filters.php` | `53ea825301a0313dad890b4e486c3f0512cf8c02081792bbe9a61a30ee2938e9` |
| `tests/cashier-v3/php/order-center-sales-export-field-parity.php` | `ea825651a866578818ca316e8b9ac9beeb4e995e36b71cb77f85603b26260d4b` |

九个源码/测试文件的暂存补丁 SHA-256：`035f369dcfa8eb22078080cb41ffad7579d13bf6faaac530353bd4cbdc2b8640`；证据文件本身不计入这个补丁指纹。

## 实际导出与逐列检查

- 本地 `18091` 门店端，扬州欧尚店，消费订单“销售日期 2026-10-01 至 2026-10-31、正常”，查询命中 78 张订单；最终导出任务显示 `125 条 · 28 个字段`。125 为商品明细行数，78 为订单数，两个粒度不可混称。
- 最终文件：`/Users/xiaojunpeng/Downloads/消费订单_20261014 (3).xlsx`，SHA-256 `ea1df585bdad26cffe924d36dc1ba4862e9880c8bbc8855f667d6fa706f94e27`。XLSX 可正常读取；表头 28 列，125 条数据分属 78 张订单。
- 28 列按字段合同逐列断言，两条不同商品的数量、单价、商品标签、手艺人类型及业绩、销售经理、导购轮次、金额、销售人业绩与页面明细一致；订单号、日期、会员、手机号、门店、商品摘要、实际下单时间、商品数量、应收、优惠、欠款、优惠券、已收、记账收款、收银员、来源、支付状态、状态、补单标记、支付完成时间均逐列检查。
- 实际文件对订单 `XS26101100001` 的 5 条商品明细与页面逐行核对：数量均为 1；第二行导购为“江慧芳（第1轮）”；第三行手艺人、销售人及金额对应同一商品；权益行销售人为“—”。该单应收、欠款、已收及记账收款仅在首条明细出现。
- 对最终文件中全部 78 组订单检查：后续明细不重复商品摘要、商品数量或整单金额；每条明细的商品、单价、数量、手艺人、销售经理、导购、金额和销售人列均有明确值或“—”。金额单元格继续保留分精度，人员展示文本遵循页面整元显示。

## 自测结果与限制

- `order-center-sales-export-field-parity.php`：28 列 × 两条明细逐列 PASS；当前页范围、历史无明细订单、游客和非销售页签粒度检查 PASS。
- 上述逐列测试在本机 PHP 及与生产相同的 PHP 7.4 容器均通过；五个后端文件经 PHP 7.4 `php -l` 逐一通过，相关差异 `git diff --check` 通过。
- `order-center-unified-export-contract.php`：36 PASS、3 FAIL；本轮导出权限契约通过，失败项为订单中心前端已有的日期默认与销售时间静态断言，前端文件不属于本轮变更。
- `r37-order-center-unified-filters.php` 的导出断言已改为明细粒度；本地容器的旧固定样本在前段“salesperson identity mismatch”处中断，未将其记作通过。`metadata-integration.php` 仅通过语法检查，未执行完整数据库集成测试。
- 导出任务响应仍会使当前列表暂时为空，重新点击“查询”可恢复；本轮未更改收银页面交互，待另行逐项批准。

## 两站只读发布预检

- 瑞昊 `rh.cc3798.com` 和渼约 008 `008.cc3798.com` 的四个既有目标 PHP 文件均与本地基线 HEAD 字节指纹一致；新投影类在两站均不存在。两站 PHP 均为 7.4.33，独立 Swoole 运行中；瑞昊 Excel worker、008 独立 AI Excel worker 运行中。
- 瑞昊最新数据库迁移日志 id `229`、渼约 008 id `21`，键均为 `20261014-001-inventory-transfer-withdrawal`；本轮无需迁移或生产数据修改。
- 两站备份根分别为 `/www/backups/rh.cc3798.com/` 和 `/www/backups/008.cc3798.com/`，均存在且所在磁盘有充足空间；部署前仍须按 commit 新建各自独立回滚包并核对旧文件指纹。
- 瑞昊正式、独立预览以及 008 门店端的磁盘入口 `index.html` SHA-256 均为 `5c1694b300bf244b1df19be998c4f472f60eff81d801187541267e670b7d1414`；各入口主 JS 为 `a11809ca6cd5571e793f865c6e087dc75f32020b6f058e54192098497e1186e4`、CSS 为 `ea17518967e1d7b0923c461b8154f7ab5e3a35bf5513cc1e6b48b2092530b7af`。本轮计划仅更新两站正式后端，瑞昊预览前后端与所有门店前端均保持原状。
- 取得新固定版本验收后，先精准 commit；按实例独立备份五个 PHP 路径并记录回滚包，再从该 commit 定向部署，分别重启其 Swoole 与导出 worker，核对运行文件散列和公开只读路径。两站线上验证通过后再按本次授权 push。

## 状态

修正后的开发与自测完成，待产品经理对新固定版本补验。未本地提交、未部署、未推送；远程仅做两站只读预检，本地容器重启不等于门店端 8080 打包或正式发布。
