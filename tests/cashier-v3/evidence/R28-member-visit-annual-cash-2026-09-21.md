# R28 会员进店分析表现金业绩日期口径自测

- 基线 HEAD：`78e6318d095f781549b3abf102c53f988f846c1c`。
- 后端源码：`后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php`。
- 回归契约：`tests/cashier-v3/php/member-visit-annual-cash-contract.php`。
- 后端源码 SHA-256：`81c183b58e9a903f7436b77809065228adfcf1aa83ac72bd0172cda04544d5c3`。
- 回归契约 SHA-256：`6c2b75695525693fc9e6493f7e3b64b966d250efa4c0f35816ab676ae19cf5bd`。
- 后端源码相对基线的补丁 SHA-256：`e68298e9c3c3c0d7894248076833c50e96592f23464783dfac5f01d88e739f81`。
- 业务口径：筛选日期决定到店会员及进店次数；该行全年及 1—12 月现金业绩读取所选自然年内该会员的有效收款事实，并计入退款反向事实；历史权益迁入订单不是新收款。

## 本地只读实测

在本地 `mohe-app` / 本地 MySQL 的现有测试记录中，以门店 `133`、到店筛选 `2026-09-04` 查询：一位会员的有效收款在 `2026-09-03`，另有 `2026-09-10` 的退款反向事实。修正后该会员到店次数为 `1`，9 月现金业绩与全年现金业绩均为 `5000` 元。旧的“只查询 9 月 4 日收款”逻辑会显示 `0`。本次未新增或修改测试业务数据。

- `php -l` 后端源码及回归测试：通过。
- `member-visit-annual-cash-contract.php`：6 项通过。
- `phase-two-report-projection-contract.php`：通过。
- `store-operations-report-contract.php`：6 项通过。
- 本地统一报表页面查询与 CSV 导出：记录、合计行和指标版本完全一致。
- `git diff --check`：通过。
- 附带运行 `store-operations-organization-dimension-contract.php` 时，因缺少 `itemAnalysisConsumptionFilters` 而失败；该方法在任务基线 HEAD 中亦不存在，与本次两文件变更无关，未顺手修改其他报表。

## 瑞昊截图样本边界

生产只读核对显示，该 8 位到店会员在 2026 年均无有效 V3 收款事实。日期口径修正后仍应显示 `0`；迁移权益订单中的项目金额不能直接转成现金业绩。若要纳入迁移前真实历史收款，须另立来源、日期、退款和门店归属的对账回填方案；本次不写生产数据。
