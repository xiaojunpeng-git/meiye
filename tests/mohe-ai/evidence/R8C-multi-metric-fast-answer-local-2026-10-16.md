# R8C 多指标门店快速回答本地验收记录

## 固定版本

- 基线 HEAD：`408f6a79e6cacd71681f80c32bd579643cae698a`
- 固定版本清单 SHA-256：`3acb40d80d35d04f74c12abaf62c4262dcb188c0b3eca89b1332dfc48d362dd8`
- 固定时间：2026-10-16（Asia/Shanghai）
- 状态：开发与 Codex 本地自测完成；产品经理已于 2026-10-16 明确确认本轮测试无问题，并授权仅提交 R8C、部署瑞昊、验证后 push，再独立部署渼约008。

`MetricDictionaryServices.php` 和 `MetricDefinitionRegistry.php` 在本轮开始前已有其他任务的未提交改动。本记录的整文件 SHA 用于冻结验收状态；提交只能逐块暂存 R8C 的词义别名、业务日期默认声明和对应注释，不得夹带两个文件中的退款口径改动。

## 完成范围

1. 将未加限定的“业绩”统一解析为“现金业绩”，“消耗”继续对应“消耗业绩”。
2. 对“一个明确时间 + 一家精确授权门店 + 1–12 个已注册指标”建立通用快速回答路径。问句完整通过语法、指标字典、门店授权和统一 Reader 合同后直接查询，不等待模型。
3. 支持一次询问多个指标，不丢弃任何已说出的指标；原问题可一次返回现金业绩和消耗业绩。
4. 指标采用最长不重叠匹配，“实际业绩”、“劳动业绩”不会被短词“业绩”抢占。未有可执行定义的“服务业绩”等仍进入受控澄清。
5. 只接受授权目录中唯一的精确门店名。模糊名、无权名、重名、多时间、环比或未解释条件均不进入快速执行。
6. 完整的新问题会重置旧上下文，不继承上一个问题的门店、日期或指标范围。
7. 无用的“业绩二次歧义”分支已从语义词表删除；旧的排行默认不再覆盖用户明确指标。

## 原问题复测

原句：`扬州大润发店本月的业绩是多少?消耗多少?`

- 语义结果：`cash_performance + consume_amount`
- 时间：`2026-10-01` 至 `2026-10-16`
- 门店：本地瑞昊副本 `store_id=179`，授权名称唯一命中
- 合成网关端到端：`COMPLETED`，模型调用 `0`，查询 `2`次（授权门店目录 + 统一指标读取），回答中同时包含“现金业绩”和“消耗业绩”。
- 本地瑞昊副本只读对账：现金业绩 `3188000` 分（`31880` 元），消耗业绩 `909500` 分（`9095` 元）；统一注册 Reader 与门店统一报表完全一致。
- 只读脚本锁定本地数据库名和 server UUID，使用 `START TRANSACTION READ ONLY` 并最终回滚。

## 自测结果

- PHP 7.4 容器语法：6 个后端文件全部通过。
- 原问题及快速入场：`deterministic-summary-admission.php` 33 项通过。
- 网关端到端：`r8c-multi-metric-store-summary.php` 9 项通过。
- 本地真实数据只读对账：`r8c-local-readonly.php` 通过，`report_parity=true`。
- 语义、排行和分组回归：`semantic-guidance` 103、`temporal-ranking` 17、`exact-ranking-collection-admission` 49、`breakdown-query` 63 项全部通过。
- 绑定、Reader 与注册表：`intent-understanding-contract` 272、`query-read-view` 65、`registry-execution` 105、`gateway-review-regressions` 85 项全部通过。
- 超时恢复与上下文：`opt1-transport-recovery-gateway` 38 项通过，`opt3-gateway-recovery` 通过。
- 前端恢复卡合同：平台 229 项、移动端 218 项通过。
- `git diff --check`：通过。

## 边界与限制

- 这一快速路径只处理可完整证明的注册指标问题，不取代模型对原因、趋势、独立时间窗口、复杂条件或模糊对象的理解。
- 本地 `18081` 当前魔核 AI 配置为停用，页面会提示检查基础配置。本轮未擅自改动运行配置；页面之外的同一网关、权限目录、Reader 和渲染合同已用合成端到端与真实本地只读数据分别验证。
- 瑞昊线上仍是旧版本；本记录不声称线上已修复，也不构成部署或 push 授权。
- `analysis-capability-catalog.php` 当前引用了不存在的旧类，`round3-acceptance-probes.php` 需要专用临时数据库，R5 冻结 holdout 仍含旧的“业绩必须歧义”预期及其他历史差异。这三项未作为 R8C 通过证据，也没有为了本轮伪造通过结果。

## 文件清单与 SHA-256

### 后端源码

- `AiGatewayServices.php` — `43cedd2b2eb25f6cf8800f9c036ebb04e470b2248d0859a6fe9e8014fd7653dd`
- `AiDeterministicSummaryAdmission.php` — `8ebb6518214108835c474f34c15542ddbcb4f7692bf29803306bf73ae09dbbd1`
- `AiSemanticVocabulary.php` — `fcb77cc514810071f58583c9b6f48d225c77701321faf45a09c1befe04eb2fc8`
- `MetricDictionaryServices.php` — `8d37de5ef649eb2f61aa4d81104f5ed2847ed534fc9279424d7d58c9cfaf20e8`
- `MetricSemanticCatalog.php` — `beaee5d6812a3e0c894037f94ccba921461b5ba28a233b41d2c2dff565b0d0f3`
- `MetricDefinitionRegistry.php` — `3e0f19b6cd7467c9c6c59c493223298faeea031d717b8da060efbc25f1cc34c1`

### 自动化测试

- `deterministic-summary-admission.php` — `b21094fa1c53d7228427e5375101aca5d8245bee76b8f24b7caa5f324e699b9c`
- `r8c-multi-metric-store-summary.php` — `dd3d876318f91566b6abe2ae37d0e862d200573098b1984b0c7a29dbd843daa5`
- `r8c-local-readonly.php` — `e3db6018bb05f1f3b0447cbb8e449f0d343555012af8b61b68ddf5b5b92a419c`
- `semantic-guidance.php` — `b092b00a88b6ee7880b1eab486ea764e1ae68300c92c80a0f41b611d63dec139`
- `temporal-ranking.php` — `23e966b491200a26c5cd0ff2dd485588c380b6730461967e800f7cf44dd7fb5d`
- `exact-ranking-collection-admission.php` — `3e97a3c9d5b87c0b8300911e24f142e8bf15fa77fcc586499ccbaf7c7b55a2fe`
- `breakdown-query.php` — `ba09f975eaba0d1a4533e1729ee16b89214328b2bfe3d17b3cfbdbffddf1c5fe`

### 生成物、数据库迁移与工程脚本

- 前端源码：无 R8C 新改动。
- 数据库迁移：无。
- 工程脚本：无。
- 生成物：无。
- 验收证据：本文件。
