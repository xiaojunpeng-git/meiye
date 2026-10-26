# R54 消费金额筛会员：本地自测与待验收固定版本

## 范围与口径

- 基线 HEAD：`689df1a6cf0a3b84add3c5f0d59e25f791ebe76a`；闭环补丁 SHA-256：`b41066164b10f8d7f69347bcb2d07576eaebd5210b364c606c0aa7b2197bc044`。
- 用户自然语言中的“消费／花了多少钱”按实际付出的款理解；“消耗／使用权益”仍属完成服务后的权益消耗，不互换。金额未带比较词时为等于。客户是计数对象，不是第二个金额指标。
- 现金业绩会员条件沿用已登记的正向收款事实（销售收款、充值收款、未分配历史欠款补交），按已授权门店、实际收款日期及会员聚合；退款不暗中抵减正向收款。明确的其他口径仍按各自指标处理。
- 只说当前自然年时，实际数据查询取年初至可信业务日；明确写出的未来截止日仍原样保留并由查询日期政策拒绝，不静默截断。

## 固定源码清单与 SHA-256

| 分组 | 文件（均相对于唯一源码正本） | SHA-256 |
|---|---|---|
| 后端 | `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `53e4712416c75819d391577bb4052d4f45b736dc2622817162d64075a2b99113` |
| 后端 | `后端代码/app/services/ai/execution/AiWorkflowPlanner.php` | `f1ddbfd818e739ca4da02c9acb7805bb72ae40a2a6ee89dae5f99c4b31599339` |
| 后端 | `后端代码/app/services/ai/semantic/AiSemanticIntentParser.php` | `4990f293cc327afb59819564b368f92c0c337532d15a803fe4b00b1a680627cf` |
| 后端业务 Skill | `后端代码/app/services/ai/skills/store_operations/SKILL.md` | `9a0aa386cd7641d3906360f4b95fc74608ea619d795e8bb52c149eea7d45763e` |
| 后端 | `后端代码/app/services/metric/MetricDictionaryServices.php` | `fdc3b1b91c39c8805aea05714dce66ed3a63efe2b0af4893330c19e3bdbae834` |
| 后端 | `后端代码/app/services/query/metric/MetricDefinitionRegistry.php` | `aa03596c639c7ecc3b4357bcf54b603101c6ea8229f3343a51ebb555158b165b` |
| 后端 | `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `82df03b6ef7d8d5153ed446be3f64573e1b1286cbf32d2cf7899c401fed6f002` |
| 自动化测试 | `tests/mohe-ai/metric-registry-contract.php` | `46a029da2905f4e4abfaa38d81750d8c87ffac77f1e14d308e8a2ba4b4014cc3` |
| 自动化测试 | `tests/mohe-ai/query-mysql.php` | `94e64e8a5164b4426f401d5180e18a7006112b5c8bb36138f5fee6ef7f0b562c` |
| 自动化测试 | `tests/mohe-ai/semantic-guidance.php` | `fa7b0d546099d5aa4a3eb272cf271e2eb4cf72f4c5e96e22aeeb856a8dbc1014` |

前端源码、数据库迁移、工程脚本、生成物：无。验收证据：本文件。改动过的业务注释同步补充于日期解析、日历规划、指标注册、指标读取、指标字典与业务 Skill；无新增平行读数实现。

## 本地验证

- 管理端“已发布版本真实试问”两次输入原句 `2026年消费4980的有多少个人`，均返回“符合全部1项条件的客户共有1人。统计时间：2026-01-01 至 2026-09-29。”；运行状态均 `COMPLETED`，分别约 11.918、11.919 秒，数据节点约 243、148 毫秒。这是本地真实数据与当前模型的两次样本，不保证每次延迟。
- `metric-registry-contract.php` 94 项、`intent-understanding-contract.php` 225 项、`condition-set.php` 83 项、`date-policy.php` 56 项、`semantic-guidance.php` 104 项、`gateway-integration.php` 155 项、`runtime-contract.php` 105 项均通过。
- `run-query-mysql.sh` 在隔离 MySQL 5.7 中通过 169 项读数检查，包含销售收款、充值、未分配历史欠款补交、退款不混入、会员金额等值计数与列表；并通过并发及管理状态测试。首次一次性 MySQL 容器未启动成功，顺序重跑通过。
- `git diff --check` 通过；PHP 改动文件语法检查通过。
- `run-all.sh` 未全绿：R5 holdout 输出 `matched=27, mismatched=13`，其中含范围待裁决和理解失败；本次未改这些样本，不能仅凭现有输出认定 13 项全是旧基线问题，也不声称整套测试通过。

## 交接门禁

开发与上述自测完成，待产品经理针对该固定版本明确确认验收。当前未 commit、未部署瑞昊、未 push、未上传体验版；线上与手机端仍未验证本次源码。仅本闭环文件可在验收后按项目规则逐项暂存，工作区其他任务改动保持原状。
