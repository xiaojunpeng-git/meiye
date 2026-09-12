# 第 7 轮修复后再验收

日期：2026-09-12。复验结论：本报告发现的三项代码问题已修复并通过完整本地回归；第 7 轮仍待三入口真实权限对账、独立留出集和培训交接，不能标为产品验收完成。

本次按对话中确认的最终方案、项目数据架构及指标层规范复核当前工作区。HEAD 为 `5eeea667edf324d512c1bc9e6edf1ebc7afea94a`；合并原 R7 清单和修复清单后，49 个文件 SHA-256 全部匹配。本报告是新的验收证据，不改写此前的固定源码清单及历史测试事实。

## 1. P1：引用员工结果会丢失前文门店范围（已修复）

位置：`后端代码/app/services/ai/AiGatewayServices.php:495–496、926–929`。

用户先限定某门店看员工排行，再说“刚才第一位员工的汇总”，只改变了人员选择，原门店范围应当保留。修复前，`resultReferenceConstraints()` 的人员分支返回 `store_ids=[]`；随后网关把整份已合并约束替换成这一结果。`IntentContextMerger::bind()` 原样接受空门店列表，Reader 将它解释为当前人员权限内的全部门店。

使用当前源码的实际引用解析器、合并器和 Reader 范围绑定方法进行隔离复现，输入权限门店 `[168,177]`，原查询门店 `[168]`，原结果员工键 `701`，得到：

```json
{
  "source_store_ids": [168],
  "resolved_reference": {
    "object_kind": "person",
    "store_ids": [],
    "business_filters": {"object_kind": "person", "selection_ref": "person:701"}
  },
  "reader_scope_before": [168],
  "reader_scope_after": [168,177]
}
```

这不是引用错人，也不必然越过账号权限；问题是无依据扩大用户明确指定的业务范围。员工在另一权限内门店有符合条件的业绩时，结果可能增加。本次没有读取真实业绩，不声称已测得具体金额差异。

修复结果：`IntentContextMerger::applyResultReference()` 成为结果引用约束的唯一合并入口。引用门店时它选择该门店；引用人员时只替换人员筛选，保留已确认门店范围。网关不再整包覆盖合并后的约束。`result-reference.php` 新增单店员工引用回归，断言原门店 `[1]` 保留、人员筛选变为 `person:7`。

## 2. P2：本人权限的能力候选与 Reader 不一致（已修复）

位置：`后端代码/app/services/ai/execution/AiAuthority.php:7–32`；`后端代码/app/services/query/metric/MetricReadViewServices.php:181–183`。

修复前，`AiAuthority::capabilities()` 仅按人员能力就绪状态移除人员指标，未按 `self_participant` 排除不能执行的门店汇总能力；返回值中也未携带足以供后续候选发现处理该权限粒度的状态。因此模型绑定和单选候选仍会收到门店汇总能力，而 Reader 明确禁止本人权限读取这些整店数据。

实际调用 `AiAuthority::capabilities()`、`AiCapabilityGuidanceCatalog::discover(...,'store','summary')`，再调用 Reader 的人员权限守卫，得到：

```json
{
  "scope_mode": "self_participant",
  "offered_store_metrics": [
    "actual_performance", "balance_deduction_amount", "cash_performance",
    "completed_service_item_count", "consume_amount", "customer_active",
    "recharge_amount", "refund_performance", "sales_amount"
  ],
  "reader_decision": "METRIC_PERMISSION_GRAIN_UNAVAILABLE"
}
```

Reader 拦截仍有效，本次未发现该路径泄露整店数字。但用户会被引导选择一个确定不能执行的候选，不符合“候选来自当前人员确实可用的能力”和“明确后立即执行”。

修复结果：能力投影在 `self_participant` 范围内仅保留注册表中 `filter_grain=person` 的已就绪指标；Reader 的本人权限守卫保持不变。`personnel-analysis.php` 新增回归：本人权限不会再收到门店汇总候选，仍能查询其已登记的人员粒度指标。

## 3. P3：日期续问可被错误 delta 扩大门店范围（已修复）

用户先在单门店范围内完成查询，之后只说“改成 9 月 9 日”。修复前，绑定模型即使没有任何“全部门店”含义，只要输出 `context_delta.store_scope=clear`，上下文合并就会删除签名的单店约束；Reader 再按账号授权门店执行。

修复后，`AiIntentResultContract` 仍在合并前核验会改变范围的 delta：清除门店范围必须同时有**当前消息**已接受的 `scope=authorized` 需求和 `scope=replace`；替换门店范围或业务对象筛选也必须有对应的当前对象需求。历史消息可用于理解续问，但不能单独解除已签名的范围。进一步地，`IntentContextMerger` 会将已签名门店范围的清除转换为服务端待确认状态；即使模型错误把日期摘录当成“全部门店”依据并且复核模型返回通过，Reader 在用户选择范围前也不会执行。

`r7-context-removal-audit.php` 的 Gateway/合并器/Reader 隔离回归覆盖：日期续问保留 `[1]` 门店、伪造清除在 Reader 前停止、历史“全部门店”不能授权日期续问扩大范围、即使注入“接受”的复核结果，日期摘录造成的范围清除也必须先呈现门店范围确认、明确“全部门店”由用户确认后才执行。输出 `AUDIT_FAILURES=0`。

## 4. 最终交卷证据仍不完整

- 三入口同一人员、相同条件的真实权限与 Reader 对账尚未交付；本人权限没有旧页面可比时，应明确登记，并用权威人员权限范围、Reader 直查及独立归属夹具作基准。
- 现有 `r5-holdout.php` 为已暴露的旧回归集，且使用本地解析器与固定编译选择，不是第 7 轮两阶段自然语言链路的独立留出验收。实际输出 `matched=31, mismatched=9, scope_adjudicated=9`；退出码 0 不能转述成 40 题全部符合原期望。
- 尚未找到第 7 轮本人权限可用范围、三入口一致性及“本店”指代变化的培训交接成果。第 6 轮培训内容不能替代本轮交接。

这些属于研发交付缺口，不应交由产品经理试页面来代替补证。

## 5. 已核验的正向结果与代码质量

- 两份运行 Skill 为步骤式自然语言说明；正式问数走独立理解、能力绑定和注册编译，未发现正式查询回退到旧关键词解析器。替换已签名对象筛选仍进入独立语义复核；解除已签名门店范围改由服务端确认步骤保护，避免把范围扩大完全寄托于模型判断。
- 上轮修复的历史消息单独触发结果引用、有意注入无当前依据的结果引用、未来日期错误优先级，现有针对性回归通过。它们不能覆盖本报告第 1 项“合法引用后丢掉其他范围”的问题。
- 日期执行规则共用 `MetricQueryDatePolicy`，含首尾上限为 366 天；Reader 的覆盖起点引用注册表。
- 已删除的 `AiQueryPlanInputValidator` 与 `mohe-plan-v2` 在当前运行源码无引用。
- 管理端草稿预览仍调用旧 `AiModelInputProjector::project()`，页面已说明不调用模型；这是尚有真实调用方的兼容路径，不能当作无用代码直接删除，也不能拿该预览的通过结果证明真实自然语言理解正确。
- 当前全量回归 `bash tests/mohe-ai/run-all.sh` 退出码 0；`git diff --check` 通过。宿主 PHP 8.5 输出 ThinkPHP 依赖弃用提示，未造成本次回归失败。

规范扫描实际结果：

```text
rg '\$metric ===|switch \(\$metric' app/services/query app/services/ai
无匹配。

rg 'distinct\(true\)->count\(' app
无匹配。

rg 'AiQueryPlanInputValidator|mohe-plan-v2' 后端代码/app 前端代码/shared/mohe-ai
无匹配。
```

页面服务事实表扫描仍有命中，集中在 `StoreUnifiedReportServices.php` 与 `MobileWarehouseServices.php` 既有路径；本次没有修改这两个文件，原分类见 `XJPMD/产品方案/2026-09-10-魔核AI第三轮V3事实直连归类表.md`。本次不将这些引用声称为已清零，也不重新扩大到冻结指标审计。

## 6. 证据边界

本次新增问题由当前代码的实际方法隔离调用复现，并在修复后用相同入口回归。完整回归为离线夹具与合同测试；不能替代真实模型、三入口业务对账及产品经理最终验收。

本次修改了 AI 后端约束合并、能力投影和对应测试；未修改 Skill、数据库或收银页面，未提交、部署或推送。
