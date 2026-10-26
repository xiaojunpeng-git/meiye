# R22 六维数据分析独立退款固定版本（2026-10-18）

## 固定基线

- 提交前 Git HEAD：`5ea310add04611f0135804d35cacfbf148f1d6c5`；其新增文件与本闭环文件无重叠。
- 当前状态：产品经理已于 2026-10-18 确认测试通过并授权按“commit → 瑞昊 → push → 渼约008”发布；提交和发布结果另行记录。
- 目标实例：仅本地 `ruihao` 只读数据核对；线上实例、备份和回滚点本阶段不适用。

## 本轮修复

1. 六维数据分析的自营、合作和全部实收业绩，在退款业务日合并没有销售分摊行的手工退款与旧卡退款。
2. 独立退款继续读取权威退款事实、当前平台商品分类和组织快照；已有销售分摊退款不重复追加。
3. 多项目整卡退款只在项目及权重全部核实时按权重稳定拆分；整元退款保持整元、总额守恒。无法核实的整笔保留为未分类，不猜测归属。
4. 六维“消耗及退款明细”使用同一退款事实总体，分类路径继续限制明细范围，退款以正数显示在“退款金额”列。
5. 截图日期 `2026-10-18` 的 `TK2610180001` 当前分类为“生美/卡项”，继续不进入六维报表；真正漏算的 `TK2610100001` 当前分类为“六维/自营”，在 `2026-10-08` 冲减 ¥5,000。

## 文件与 SHA-256

- `后端代码/app/services/report/StoreUnifiedReportPhaseThreeServices.php`
  - `8be717eb3697921d92d9a1883c1d37a80bda3b6a9ed2818d4fcbf2ddcb847af4`
- `后端代码/app/services/report/StoreUnifiedReportPhaseFourServices.php`
  - `8eb261ce4f0fc3b7b87544b087ef6d28392b2cb254870372dfb5aeee8c3340b4`
- `后端代码/app/services/report/StoreRefundCurrentCategoryServices.php`
  - `622b29e1d71d2bbf0299dd2b23140bad0ecde6e804cac8d2dac8c312174866c4`
- `tests/report-phase-four/php/r22-six-dimension-standalone-refund-local.php`
  - `e00f1dbb0301ce11829871254158f2b62fffc39eb67303ee26682bb6a421d9d1`

## 自测结果

### R22 真实数据只读对账

命令：

```bash
docker exec -i mohe-app php < tests/report-phase-four/php/r22-six-dimension-standalone-refund-local.php
```

结果：10 项全部通过。

- `TK2610100001` 只解析为一条“六维/自营”独立退款，金额 `-500000` 分。
- 销售分摊现金行中没有该退款；完整六维现金总体只追加一次。
- `TK2610180001` 为“生美/卡项”，没有进入六维总体。
- 门店 145 在 `2026-10-08`：自营 `-500000` 分、合作 `0` 分、全部 `-500000` 分。
- 主表自营/合作/全部实收与完整有符号现金总体一致。
- 分公司组织快照保留，没有落入“未配置分公司”。
- 退款明细中同一退款只出现一次，并在“退款金额”显示 `500000` 分。
- 整卡多项目分摊验证总额守恒、整元保持整元；无效权重回退未分类。

### 受影响回归

- PHP 语法检查：3 个业务服务文件与 1 个测试文件全部通过。
- `git diff --check`：通过。
- 第三阶段静态报表契约：本次涉及的报表代码、字段和事实读取断言通过。
- 第三阶段真实数据库查询烟测：6 张报表查询、分页结果、合计与权限范围全部通过，`failed=0`。
- 健康数据退款事实回归：通过，现金、退款、实际业绩及 XLSX 明细一致。
- 生美分类报表的动态列、汇总与下钻逐项对账通过；旧脚本最后一条固定金额样本已随本地数据增长失效，不属于本次差异。

## 注释更新

- `StoreUnifiedReportPhaseFourServices.php`：说明销售分摊退款与独立退款的互斥来源、成交资格边界和六维专属分类展开。
- `StoreUnifiedReportPhaseThreeServices.php`：说明汇总分类如何约束明细，以及独立退款不得与销售分摊退款重复。
- `StoreRefundCurrentCategoryServices.php`：说明退款分摊的总额守恒、整元规则与无效权重回退策略。
