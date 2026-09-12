# 第 7 轮整体复核：自然语言理解与执行边界

日期：2026-09-12。核对基线 HEAD：`5eeea667edf324d512c1bc9e6edf1ebc7afea94a`，含当前未提交的 R7 修复。

结论：第 7 轮已经接入模型理解，但“由模型理解、由代码忠实绑定和执行”尚未完整落地。当前存在模型正确理解后被结构比较拒绝、明确筛选被遗漏、对象分支自行补充结果数量、明确请求被强制追问等问题。不能用已有离线回归通过认定本轮目标完成。

本次只审查与复现，没有修改运行代码。复现通过现有 `R6GatewayHarness` 调用当前 Gateway、合同、合并器、编译器及 Reader，使用内存 SQLite、合成事实和固定模型响应。它证明这些后端路径的确定行为，不证明真实模型发生错误的概率，也不代表真实业务金额对账。

## 1. P1：首轮已点名门店，执行却丢掉该筛选

- 模型已输出 `object_kind=store`、`object_term=一号门店`；理解结果明确保留“仅查看一号门店”。
- 首轮没有前文查询，`AiGatewayServices` 将 `store_scope` 设置成 `inherit`。
- `bindNamedStoreScope()` 只在 `store_scope=replace` 时绑定门店，首轮因此跳过。
- 最终 `query.store_ids=[]`；Reader 的范围绑定将空请求列表解释为当前全部授权门店。

隔离实跑结果：

```json
{"case":"first_turn_named_store","status":"COMPLETED","reason":"","model_object_term":"一号门店","authorized_store_ids":[1,2],"query_store_ids":[],"reader_transactions":1}
```

来源：`AiGatewayServices.php` 的 `bindNamedStoreScope()` 及首轮 `contextDecisions` 初始化；`MetricReadViewServices::binding()`。

这是执行限定丢失，不是语言理解失败。未声称实测了真实门店的金额差额。

## 2. P1：相同时间含义因表示形式不同被判失败

前文“本月现金业绩”完成后，服务端将月份保存成 `2026-09-01` 至 `2026-09-12`。续问“还是看本月现金业绩”，模型保持 `month_offset=0` 并声明 `periods=inherit`。本轮可信日期相同，两者实际期间相同。

`AiIntentResultContract::assertRequirementValues()` 却直接调用递归结构比较 `equivalent()`，把月份表达和具体区间的不同当成意思冲突，尚未换算成可比较的期间就拒绝。

```json
{"case":"same_month_different_representation","source_status":"COMPLETED","source_range":["2026-09-01","2026-09-12"],"understood_period":[{"kind":"month_offset","offset_months":0}],"delta":"inherit","next_status":"FAILED","reason":"AI_MODEL_INTENT_CONTRACT_INVALID","new_reader_transactions":0}
```

需要统一的是可比较的含义及其时间基准。不得通过新增“还是看本月”等问句匹配来处理。

## 3. P2：对象分支自行决定未指定的展示数量

对会员与门店分别注入同样的“从高到低、数量未指定”理解结果，时间、指标及权限相同：

```json
{"case":"member","model_limit":null,"status":"COMPLETED","query_limit":5,"clarification_field":null}
{"case":"store","model_limit":null,"status":"WAITING_CLARIFICATION","query_limit":null,"clarification_field":"rank_limit"}
```

`AiDimensionGuidancePlanner::start()` 在方向明确且数量为空时直接赋值 `5`；门店规划器则要求选择数量。前者注释称其为发布的默认值，但当前赋值没有读取共同的产品约定来源。默认展示数量可以作为产品约定存在，但不能由对象分支各自决定，更不能冒充用户已表达的数量。

## 4. P2：解除已选门店范围被无条件解释为需要追问

`IntentContextMerger::merge()` 对已选门店范围的 `clear` 无条件添加 `pending_store_scope`。现有 `r7-context-removal-audit.php` 已复现：用户明确“改为全部门店，9月9日”，仍得到 `WAITING_CLARIFICATION`，选择后才执行。

此规则确实阻断了错误范围扩大，但同时将明确请求变成额外交互。`AiPendingContextGuidancePlanner` 又统一提示“这一项尚未能从本轮问题中准确确认”，将代码设置的确认要求描述成理解不清。防止无依据的筛选变更与判断用户是否表达清楚，需要分别落实。

## 5. 理解表达与可执行结构仍有耦合

`AiIntentUnderstandingContract` 虽然不再要求第一阶段选择指标编码，但仍要求把需求放进固定 `fields/values`，对象、结果形态、范围及日期具有封闭结构。复杂关系只能留在 `meaning` 或归入 `unbound`；当前没有条件关系的正式表示，Gateway 遇到任意 `unbound` 就直接返回 `AI_ANALYSIS_COMBINATION_UNAVAILABLE`。

这保证了不能执行的请求不会被悄悄删条件后执行，但还没有实现“先完整表达意思，再依据实际登记能力判断能否绑定”。结构化传递本身不是错误；需要防止用执行字段是否容纳得下，替代语义是否已经理解的判断。本轮不执行多步组合的范围仍可保留。

两份 Skill 的自然语言方向总体正确，但实际模型输入还叠加 PHP 中的协议提示。`SiliconFlowClient::understandMeaning()` 要求 `Do not infer a missing condition from history`，而意图 Skill 要求简短续问沿用有效前文。应明确区分“沿用已确认含义”与“从历史猜一个新条件”；当前措辞有冲突风险。本次未调用真实模型，未将其说成已测得的失败率。

## 6. 哪些实现值得保留

- 正式问数由 `AiSafeQuestionProjector` 提供脱敏后的自然语言，没有再用旧业务词表重建整句。
- 理解与能力绑定已经分成两次模型阶段；第一阶段没有当前能力目录。
- `IntentContextMerger` 已成为有名字的上下文合并入口；签名查询、可信结果引用、人员权限和统一 Reader 的保护仍有必要。
- 日历有效性、可信业务日期换算、已登记查询跨度和覆盖起点属于执行检查，应继续由代码负责。
- 旧 `AiSemanticIntentParser` 在管理预览仍有调用，正式问数不走该解析器。不能把“仍有旧解析器文件”直接等同于正式问数继续匹配关键词，也不能在未替换调用方前直接删除。

## 7. 为何既有回归未能证明目标完成

`r6-gateway-harness.php` 的默认模型替身仍调用旧 `AiModelInputProjector::modelView()` 构造信号，并默认让语义复核返回 `accept`。它可以检查状态机与接口，却不能验证自然语言理解质量。最新范围测试又把明确“全部门店”后继续追问写成通过条件，因此测试通过也不能证明“不必要追问”得到解决。

后续应分别验证：模型保留了哪些真实含义；编译后每项限定是否与之对应；查询结果是否符合权威数据口径。以预先确定的用户期望和独立真实问题验收，不能仅依照当前分支行为更新测试期望。已有三入口真实对账、独立留出题和培训交接缺口仍未因此消失。

## 整改落点

先修复首轮门店筛选遗漏与时间等价判断，再统一确认、默认值和语义表达的责任边界。保留权限、指标登记、签名上下文、数据边界等必要校验；移除替用户增加要求、把实现限制说成歧义或忽略明确要求的行为。这里是整体审查结论，不构成新的业务口径批准或上线验收。
