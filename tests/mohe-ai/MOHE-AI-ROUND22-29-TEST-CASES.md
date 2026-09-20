# 魔核 AI 第 22—29 轮长期回归测试用例

> 版本日期：2026-09-20
> 适用范围：魔核 AI 经营问数的语义理解、注册指标绑定、上下文继承、组合条件、回答呈现与安全失败。
> 文档性质：长期保留的测试设计，不是某次生产部署证明，也不把合成夹具结果冒充真实经营数据。

## 1. 目的与边界

本用例集将第 22—29 轮已经由自动化合同、合成数据回归或同窗口真人测试证明的能力固化为可重复验收步骤，重点验证：

1. 一个问题的第一次回答尽量直接、完整，不因可安全选择默认口径而反复要求用户选指标。
2. 先识别答案对象，再从注册表选择与该对象兼容的指标；相同自然语言在不同对象下可以绑定不同事实。
3. 同话题追问只修改用户本轮明确改变的条件；自然换话题时清除不再适用的旧对象和私有筛选。
4. 查询、排行、计数、名单和明细均使用注册指标、统一 Reader、后端权限与签名上下文，不在前端或模型中重算业务结果。
5. 模型超时、语义不可执行或历史覆盖不足时，不伪造数字、不删减条件，并明确说明未执行或能力缺口。

本文件只覆盖已有证据支持的能力。预约客户数、优质客户名单，以及“超过 90/120 天未到店”的完整历史查询没有被写成正向成功用例；其中未到店天数当前因历史服务覆盖不完整，应按负向用例返回能力缺口。

## 2. 通用前置条件

| 编号 | 前置条件 |
| --- | --- |
| P-01 | 使用 `美容源码/` 当前固定版本；后端、执行消费者和对应平台前端来自同一源码版本。 |
| P-02 | 以 `Asia/Shanghai` 的服务端可信日期解析“今天、昨天、本月、最近 30 天”等相对日期；不得从浏览器自行计算经营日期。 |
| P-03 | 使用已登录且具备对应门店、人员或会员读取权限的测试账号；所有结果只能缩小后端授权范围。 |
| P-04 | 指标注册表、对象能力目录、统一 Reader 和查询执行能力均已加载；未就绪指标必须保留其就绪原因。 |
| P-05 | 涉及连续追问的步骤必须在同一个真实对话窗口依次执行，不新建会话、不手工改上下文。 |
| P-06 | 真人环境的数值以同日期、同范围、同指标的统一报表/Reader 为基准；本文不硬编码会随业务数据变化的金额、人数或名称。 |
| P-07 | 合成合同测试使用文件内固定夹具，只验证口径、绑定、权限和结果形态，不作为真实客户数据证据。 |

## 3. 结果核对约定

- **对象**：最终回答行代表的业务对象，例如门店、人员、会员、项目、卡项或产品。
- **指标**：必须是注册表中的指标代码；显示名称可用业务中文，但不能临时换公式。
- **日期**：答案必须显示实际执行的统计起止日期；日期追问只替换期间时，其他已确认条件保持不变。
- **结果形态**：概览、汇总、排行、条件计数、条件名单/明细或安全失败。
- **数据验收**：真实数据不在本文件写死；应与相同权限、范围、期间和指标的统一 Reader/报表对账。
- **失败验收**：未执行查询、条件未被删减、没有误导性 0 值或伪造名单，且提示可理解。

## 4. 测试用例

### A. 经营概览与完整首答

| 用例编号 | 输入与上下文 | 预期对象 / 指标 / 日期 / 结果形态 | 验收要点 | 证据 |
| --- | --- | --- | --- | --- |
| MHAI-OV-001 | 新对话输入“今天经营得怎么样？” | 门店经营对象；注册表声明的经营概览指标集合；今天；概览 | 首答直接返回本期经营概览，不要求用户先选一个指标；不得混入人员粒度指标；每个指标显示业务名称、值、单位和统计日期。 | `overview-metric-resolver.php`、`intent-understanding-contract.php`、R27 真人记录 |
| MHAI-OV-002 | 新对话输入“这个月经营情况如何？” | 门店经营对象；经营概览指标集合；本月月初至可信业务日；概览 | 多个概览指标同答展示，金额按元整数显示；前端不自行合计。 | `overview-metric-resolver.php`、`gateway-review-regressions.php` |
| MHAI-OV-003 | 先执行 OV-001，再依次问“昨天呢？”、“这个月呢？” | 对象与概览指标集合继承；日期依次替换为昨天、本月；概览 | 纯日期续问不重新选指标、不改写或清空上一问已确认的概览指标，不调用不必要的绑定模型。 | `context-delta-gateway.php`、R27 真人记录 |
| MHAI-OV-004 | 先问今日经营概览，再问“今天劳动业绩第一名是谁？”，再问“这个月呢？” | 第二问切换为人员 / `staff_labor_yeji` / 今天 / 排行；第三问仅改为本月 | 自然语言决定换题，不依赖“换个话题”字样；旧概览指标不得串入人员排行，第三问只继承新的人员话题。 | `context-delta-gateway.php`、R27 真人记录 |

### B. 人员、门店和具体对象理解

| 用例编号 | 输入与上下文 | 预期对象 / 指标 / 日期 / 结果形态 | 验收要点 | 证据 |
| --- | --- | --- | --- | --- |
| MHAI-OBJ-001 | “今天销售业绩第一名是谁？” | 人员；`staff_sales_yeji`；今天；前 1 排行 | “销售业绩”按人员销售业绩事实回答；结果人口来自本期间有对应事实归属的人员，不按某个互斥员工身份开关裁掉有效人员。 | `personnel-analysis.php`、R27 真人记录 |
| MHAI-OBJ-002 | “今天劳动业绩第一名是谁？” | 人员；`staff_labor_yeji`；今天；前 1 排行 | 绑定劳动业绩事实；不得误用销售人业绩或整店经营指标。 | `metric-registry-contract.php`、`gateway-review-regressions.php` |
| MHAI-OBJ-003 | 对 OBJ-001 依次追问“这个月呢？”、“改按服务次数”、“前五名”、“昨天呢？” | 人员；先继承销售业绩，后替换为 `staff_service_num`；对应期间；排行 | 每轮只改变用户说出的日期、指标或数量；对象和排行方向稳定，不重复询问已确认条件。 | `context-delta-gateway.php`、R27 真人记录 |
| MHAI-OBJ-004 | 选择一个已授权员工，问“某员工的销售业绩和劳动业绩怎么样？” | 同一人员；`staff_sales_yeji` + `staff_labor_yeji`；提问日期；双指标汇总 | 同一个人可以同时是销售事实和劳动事实参与者；“销售人/手艺人”描述计算归属，不是互斥人员身份。 | `personnel-analysis.php` |
| MHAI-OBJ-005 | “世纪中心业绩怎么样？”（名称替换为测试实例中一个已授权门店简称） | 门店；与问题语义兼容的经营指标；默认/明确期间；门店汇总 | 通过授权对象目录解析门店，不因名称像人名而猜测为客户；门店范围由后端复核。 | `query-context.php`、`context-delta-gateway.php` |
| MHAI-OBJ-006 | “王湘英今天花了多少钱？”（使用测试夹具或授权会员） | 会员；`sales_collected_amount`；今天；指定会员汇总 | 结合“花了多少钱”和本地授权会员目录识别会员；姓名/昵称仅通过精确目录绑定，不把纯金额数字当姓名。 | `member-analysis.php` |
| MHAI-OBJ-007 | 同名员工存在于两个门店时，按姓名查询 | 人员；待用户选择精确员工；原期间；对象澄清 | 必须展示带权威门店标签的候选，不能静默挑选同名对象或扩大范围。 | `personnel-analysis.php` |

### C. 项目、卡项、产品与排行首答

| 用例编号 | 输入与上下文 | 预期对象 / 指标 / 日期 / 结果形态 | 验收要点 | 证据 |
| --- | --- | --- | --- | --- |
| MHAI-RANK-001 | “这个月项目卖得最好的是什么？” | 项目；默认排序指标 `sales_amount`；本月；排行 | 首答直接给项目排行，不因“卖得最好”理论上可观察多个指标而先追问；只有一个排序指标。 | `intent-understanding-contract.php`、`skill-semantic-projection.php` |
| MHAI-RANK-002 | 执行 RANK-001 | 项目；按 `sales_amount` 排序；本月；一个排行表 | 同一张表可附带注册表声明且同批可读的 `completed_service_item_count`、`sales_quantity` 证据列；不得展开成三个排行表。 | `r27-ranking-presentation.php` |
| MHAI-RANK-003 | “项目、卡项、产品卖得最好的分别是什么？” | 三个独立对象；各自默认 `sales_amount`；默认/明确期间；三组排行 | 一次首答返回三组结果，不要求逐个选指标；卡项、产品只展示各自兼容的注册证据列。 | `intent-understanding-contract.php`、R27 真人记录 |
| MHAI-RANK-004 | 对 RANK-003 依次问“这个月呢？”、“前两名”、“昨天呢？” | 三组对象与排序指标继承；日期/排行数量按轮替换；三组排行 | 日期与排行数量对三个签名子查询对称生效，不只修改第一组，也不把三个对象合并。 | `context-delta-gateway.php`、R27 真人记录 |
| MHAI-RANK-005 | 先问门店现金业绩排行，再问“项目销售第一名是谁？” | 项目；`sales_amount`；继承仍适用的期间；前 1 排行 | 新对象替换旧门店对象和门店私有筛选；未重新表达但仍适用的日期可继承。 | `context-delta-gateway.php` |
| MHAI-RANK-006 | 先问门店排行，再只问“产品呢？”且没有表达评价口径 | 产品；候选仅含产品已注册可执行指标；原期间；必要澄清 | 只有意图确实无法选出忠实默认口径时才询问；选项来自注册表，伪造或不兼容指标不能执行。 | `context-delta-gateway.php`、`skill-semantic-projection.php` |

### D. 条件集合、计数、名单和明细追问

| 用例编号 | 输入与上下文 | 预期对象 / 指标 / 日期 / 结果形态 | 验收要点 | 证据 |
| --- | --- | --- | --- | --- |
| MHAI-COND-001 | “本月累计实际收款销售额达到 1000 元的客户有多少？” | 会员集合；`sales_collected_amount >= 1000元`；本月；条件计数 | 返回符合条件的会员人数和统计期间；金额转为注册存储单位执行，不暴露会员 ID。 | `gateway-review-regressions.php`、`condition-set.php` |
| MHAI-COND-002 | 在 COND-001 后问“明细给我看一下” | 继承完整会员条件集合；相同期间；条件名单 | 只把结果形态从 count 改为 list；对象、指标、阈值、关系和日期均继承，不把“明细”误解为排行第一名引用。 | `condition-set.php`、R27 真人记录 |
| MHAI-COND-003 | 在 COND-001/002 后问“金额门槛改成 1 万元” | 会员；仅金额阈值替换；原期间；原结果形态 | 唯一金额条件可定向修改；其他条件、对象、AND/OR 关系和注册指标顺序不变。存在两个金额条件时不得猜测目标。 | `condition-set.php` |
| MHAI-COND-004 | “最近 30 天来过至少 3 次，而且实际收款销售额达到 2 万元的客户有几个？” | 会员；`member_service_visit_count >= 3` AND `sales_collected_amount >= 20000元`；最近 30 天；条件计数 | 两项条件必须同时成立；不得将“并且”改成 OR，也不得删除一项后扩大结果。 | `condition-set.php` |
| MHAI-COND-005 | 对 COND-004 问“明细有哪些？” | 完整会员条件集合；原 30 天；条件名单 | 列表每个对象同时带出已注册条件指标，结果上限/截断状态明确；条件与计数使用同一签名查询。 | `condition-set.php` |
| MHAI-COND-006 | “最近 30 天销售业绩达到 5 万元并且服务次数达到 24 次的员工有哪些？” | 人员；`staff_sales_yeji >= 50000元` AND `staff_service_num >= 24次`；最近 30 天；条件名单 | 自然语言条件逐项绑定，保留顺序、阈值和单位；金额使用分，服务人次使用其注册存储精度。 | `condition-set.php` |
| MHAI-COND-007 | “最近一个月销售业绩达到 5 万、服务次数达到 24 次、项目数达到 15 项的员工有哪些？” | 人员；三个注册指标全部 AND；最近一个月；条件名单 | 三条件不可被注册信号顺序重排或遗漏；人员项目数使用 `staff_project_num`。 | `condition-set.php` |
| MHAI-COND-008 | 对同一条件集合分别问“有几个？”和“明细有哪些？” | 同一对象、指标、条件、期间；计数与名单切换 | 只改变响应形态；计数、名单、导出应来自同一验证行集，不能名单另算一套。 | `condition-set.php` |
| MHAI-COND-009 | 对产品问“销售额达到 5000 元并且销量达到 10 件的有哪些？” | 产品；`sales_amount` + `sales_quantity`；明确/默认期间；条件名单 | 通过通用已注册对象路径执行；同一机制可承载订单、销售明细、卡项、项目和产品，不为产品写专用问句分支。 | `condition-set.php` |
| MHAI-COND-010 | “现在有剩余项目次数，但是超过 90 天没来的客户有多少？”；另测“曾服务但超过 120 天没来的客户” | 会员；`member_remaining_project_times` 与 `member_days_since_last_visit`；当前快照；安全能力缺口 | 两个条件都被识别且单位分别为次/天；由于历史服务覆盖当前不完整，不得删除“未到店天数”后只查剩余权益，也不得伪造计数/名单，应明确组合暂不可执行。 | `condition-set.php`、`MetricDefinitionRegistry.php` |

### E. 人员项目数与项目完成数的对象优先绑定

| 用例编号 | 输入与上下文 | 预期对象 / 指标 / 日期 / 结果形态 | 验收要点 | 证据 |
| --- | --- | --- | --- | --- |
| MHAI-METRIC-001 | “本月完成服务项目数量最多的员工是谁？” | 人员；`staff_project_num`（工资项目数/分配项目数）；本月；前 1 排行 | “项目”出现在指标短语中不能抢走显式答案对象“员工”；不得绑定项目维度完成数。 | `metric-registry-contract.php`、`gateway-review-regressions.php`、第29轮真人测试 |
| MHAI-METRIC-002 | “本月完成服务项目数量最多的项目是什么？” | 项目；`completed_service_item_count`；本月；前 1 排行 | 独立出现的对象“项目”优先；不得绑定人员工资项目数。 | `metric-registry-contract.php`、`gateway-review-regressions.php`、第29轮真人测试 |
| MHAI-METRIC-003 | “本月完成服务项目数量最多的是谁？”且没有可证明对象 | 未确定对象；两个兼容指标候选；本月；澄清 | 缺少对象会实质改变答案时不得猜人员或项目，必须询问分析对象。 | `metric-registry-contract.php` |
| MHAI-METRIC-004 | 先完成 METRIC-001 或 METRIC-002，再问“昨天呢？” | 原对象和原指标保持；昨天；原排行 | 日期续问仅替换日期；人员问题继续使用 `staff_project_num`，项目问题继续使用 `completed_service_item_count`。 | `context-delta-gateway.php`、`gateway-review-regressions.php`、第29轮真人测试 |

### F. 话题切换、上下文安全与失败表达

| 用例编号 | 输入与上下文 | 预期对象 / 指标 / 日期 / 结果形态 | 验收要点 | 证据 |
| --- | --- | --- | --- | --- |
| MHAI-CTX-001 | 先问客户条件集合，再直接问“今天劳动业绩第一名是谁？” | 从会员条件集合切换为人员 / `staff_labor_yeji` / 今天 / 排行 | 不要求用户先说“换个话题”；旧会员阈值、名单形态和私有选择必须清除。 | `gateway-review-regressions.php`、`context-delta-gateway.php` |
| MHAI-CTX-002 | 先问人员排行，再直接问一个自足的项目排行问题 | 项目及其注册指标；新问题日期或仍适用期间；排行 | 自足新问题由当前语义主导；旧人员选择和岗位筛选不能泄漏进项目查询。 | `context-delta-gateway.php`、`intent-understanding-contract.php` |
| MHAI-CTX-003 | 已完成某指标查询后问“这个月呢？”或“昨天呢？” | 原对象、指标、形态、权限保持；仅替换期间 | 使用已签名上下文；不得让模型凭历史答案改写已确认指标，也不得出现误导性全 0 概览。 | `context-delta-gateway.php`、R27 真人记录、第29轮真人测试 |
| MHAI-CTX-004 | 用户明确说“不要退款”，但模型绑定到退款业绩 | 不执行 Reader；语义拒绝；安全失败 | 排除条件不能被模型反向选成查询指标；失败前查询次数不增加。 | `context-delta-gateway.php`、`semantic-binding-guard.php` |
| MHAI-CTX-005 | 模型在理解或绑定阶段超过安全等待时间 | 对象/指标/日期均不产生业务结果；安全失败 | 页面说明“模型响应超时，本次尚未执行数据查询，可直接重试”；不得自动重复模型调用，也不得显示 0 值假结果。 | `gateway-integration.php`、R27 真人记录 |
| MHAI-CTX-006 | 语义方向已理解，但底层无法完整执行对象、指标与条件组合 | 保留完整请求；能力缺口；安全失败 | 明确没有替换指标或删减条件；不能回退为范围更大的查询，也不能笼统声称已经读取或计算数据。 | `condition-set.php`、`context-delta-gateway.php` |
| MHAI-CTX-007 | 同一会话连续执行“人员 → 门店 → 项目/卡项/产品 → 客户集合 → 经营概览”，每类至少 3 次追问 | 每次新话题使用自己的对象和指标；本轮明确改动被继承；多话题连续对话 | 检查没有跨话题的对象、阈值、排行、日期或私有选择污染；每轮答案可追溯到当前签名查询。 | R27 真人记录、`context-delta-gateway.php` |

## 5. 自动化执行清单

以下测试共同构成本用例集的主要可重复证据；执行数量以测试脚本当前输出为准，不在文档中写死：

```bash
php tests/mohe-ai/overview-metric-resolver.php
php tests/mohe-ai/metric-registry-contract.php
php tests/mohe-ai/gateway-review-regressions.php
php tests/mohe-ai/intent-understanding-contract.php
php tests/mohe-ai/context-delta-gateway.php
php tests/mohe-ai/personnel-analysis.php
php tests/mohe-ai/member-analysis.php
php tests/mohe-ai/condition-set.php
php tests/mohe-ai/r27-ranking-presentation.php
php tests/mohe-ai/semantic-binding-guard.php
php tests/mohe-ai/semantic-guidance.php
php tests/mohe-ai/skill-semantic-projection.php
php tests/mohe-ai/query-read-view.php
php tests/mohe-ai/gateway-integration.php
php tests/mohe-ai/runtime-contract.php
node tests/mohe-ai/browser-entry-contract.mjs
```

需要数据库或完整应用容器的测试必须按各脚本自身前置条件执行，不能把因环境缺失而跳过写成通过。真人测试记录至少保存：源码 commit、问题链、账号权限类型、统计日期、实际结果形态、同口径对账结果和发现的问题。

## 6. 已有证据索引

| 轮次 | 固定提交/增量 | 主要证据 |
| --- | --- | --- |
| 22 | `ac9cbfdf` | `overview-metric-resolver.php`、`gateway-review-regressions.php`、`intent-understanding-contract.php`、`metric-registry-contract.php` |
| 23 | `ddd6c13c` | `gateway-integration.php`、`runtime-contract.php` |
| 24 | `e648d10c` | `member-analysis.php`、`personnel-analysis.php`、`query-context.php`、`gateway-review-regressions.php` |
| 25 | `681447d8` | `condition-set.php`、`member-analysis.php`、`query-mysql.php` |
| 26 | `d4a78b4d`、`4a32a5ee` | `context-delta-gateway.php`、`intent-understanding-contract.php`、`query-context.php` |
| 27 | `fd8459ef` | `r27-ranking-presentation.php`、`tests/mohe-ai/evidence/R27-live-context-acceptance-2026-09-20.md` |
| 28 | `b48840dc` | `personnel-analysis.php`、`context-delta-gateway.php`、`gateway-review-regressions.php` |
| 29 | 当前待验收增量 | `metric-registry-contract.php`、`gateway-review-regressions.php`，以及 2026-09-20 同窗口人员/项目与“昨天呢”真人复测 |

## 7. 明确不应误判为通过的场景

1. `member_days_since_last_visit` 当前登记了业务语义和单位，但因 `HISTORICAL_SERVICE_COVERAGE_INCOMPLETE` 未开放真实查询；任何 90/120 天未到店名单都不能记为成功数据验收。
2. 没有真实账号、真实权限和统一报表/Reader 对账时，自动化夹具通过只说明契约正确，不说明真实经营数字已验收。
3. 模型超时后显示安全提示属于失败处理通过，不代表原业务问题查询成功。
4. 用户未表达且会实质改变答案的对象、指标、范围或条件，不得为了“完整首答”而猜测；完整首答只允许使用注册表与语义复核证明安全的默认决策。
5. 本文件不授予部署、生产数据库操作或推送权限；发布仍须以固定 commit、目标瑞昊实例、备份/回滚点和部署后真实验证为准。
