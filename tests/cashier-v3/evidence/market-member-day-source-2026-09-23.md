# 市场明细按日期、会员、来源合并：本地自测与验收版本

- 基线 HEAD：`e5837178ba17df6c87750ffdd9423282322da872`（本任务提交前）。
- 已跟踪闭环文件的 `git diff --binary` SHA-256：`a085142b5c634fed05c19151b8a61bbc63afe7131da20aa8125e6c9055af9890`；新增聚合测试见下表单文件指纹。
- 范围：日期替换单据号；门店＋业务日期＋会员 ID＋来源分行，金额按行求和；正常服务按会员每日来源 0/1，明细合计和市场业绩表同步；进店保存到该合并行。
- 手动值：新行键 `market-day-v1:门店:日期:会员:来源` 独立保存并带版本、审计和幂等；旧逐单值仅在新行未保存时作为显示默认值。个人参与范围不允许改写可能包含他人订单的全店合并值。
- 业务源：有效收款事实、完成且未作废的服务事实；作废销售及服务按原受控范围排除。无收款正常服务也可形成 0 元行。没有会员 ID 的历史行不与其他顾客合并，暂不允许保存本行进店。

## 文件 SHA-256

| 类别 | 正本相对路径 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php` | `28c4c711270c006aa1885b1be4f39f2b9dbc8ab1b04dda31304e2c97186f2239` |
| 后端源码 | `后端代码/app/services/report/StoreOperationsReportAnnotationServices.php` | `0f882f02ace66d632d64155017266ae99ec0e6487628f856a0ea72f06fb32d10` |
| 后端源码 | `后端代码/app/services/report/StoreReportParticipantScopeServices.php` | `84643e9b5324810de5d1cb6c005380771c42a1842093c13a7e32ef0776a4c4c8` |
| 前端源码 | `前端代码/cashier-v3/src/views/StoreBusinessReportView.vue` | `723ce560841d8e4d0c586c0a62fad575c81d38e42168b46923e379ac1075a76c` |
| 自动化测试 | `tests/cashier-v3/php/market-b-zero-cash-service-mysql-integration.php` | `a8a9078a65ade8b01f331b998cda5d61581c74a5398c57759b18db43b44e2d16` |
| 自动化测试 | `tests/cashier-v3/php/market-detail-summary-row-contract.php` | `1edd81c105da54c40f7fc4b912ee675c43d1cc53e58e01c8a8fba9dd470fc6be` |
| 自动化测试 | `tests/cashier-v3/php/market-detail-fixed-table-contract.php` | `298b1e122861f182659c2732cd399b038883b4a0927cf8a381c4d52e5797d2b4` |
| 自动化测试 | `tests/cashier-v3/php/market-performance-service-visit-contract.php` | `3e28733794e47aea33d686e2ca2bf82dbae6cbf2ba9e04e5fe8e0835fa3c21d2` |
| 自动化测试 | `tests/cashier-v3/php/market-member-daily-aggregation-contract.php` | `c84637c5687f3d4bc5c69028505665831c8a88dc9da41c2778ead31d493ba559` |

## 验证

- PHP 语法：三份修改过的后端服务均通过；`git diff --check` 通过。
- 日会员来源聚合契约、市场服务人次契约 13/13、市场明细合计契约 7/7、会员列契约 4/4 通过。
- 本地 MySQL 事务回滚用例通过：同会员同日同来源两张 ¥0 服务单合并；多条服务仅记 1；新行进店覆盖旧单据默认值；0 和空值清除读回；保存版本、幂等、负数及不存在行校验。
- 门店端 Vite 构建通过。浏览器核对本地市场明细日期列、查询结果、本行进店编辑与取消、列名取值说明；未在真实业务行执行保存。
- 固定表格旧契约 3/5：两条关于 CSS 字符串及 `fixedColumnStyle` 出现次数的断言在本次修改前已失败，当前实际页面表头和固定列可见，另行治理测试契约。
- 旧的 `report-self-participant-mysql-integration.php` 依赖未挂载到 PHP 容器的测试库，未作为本次通过证据。

## 发布状态

产品经理于 2026-09-23 对上述固定版本要求 commit，视为本次闭环验收确认。本地提交单独执行；未部署、未 push。无数据库迁移、工程脚本变更。前端本地 `dist/` 是忽略的生成物，不纳入源码清单。
