# 第 6 轮 · 旧追问执行路径删除清单

| 已删除项 | 原用途 | 替代入口 | 下游核验 |
| --- | --- | --- | --- |
| `execution/AiFollowupQueryPlanner.php` | 以关键词解析追问 | `IntentContextMerger` + `intent-result-v3` | Gateway 不再引用；测试改为模型 Delta 夹具 |
| Gateway 旧投影/`select()` 执行分支 | 本地信号、旧模型选择、兜底编译 | `understandAnalysis()` | 未启用安全自然语言契约时明确停止 |
| `inheritContext` / `registeredSelectionFallback` | 局部隐式继承/本地补选 | 无 | 模型 Delta 必须显式，服务器只验证和合并 |

删除后核验命令（源代码范围，不将历史交接记录当成可执行引用）：

~~~sh
rg -n 'AiFollowupQueryPlanner|AiQueryContext|inheritContext|registeredSelectionFallback' \
  后端代码/app/services/ai tests/mohe-ai --glob '!R*-FIXED-VERSION.json' --glob '!FOLLOWUP-REPAIR.md' --glob '!R6-CONTEXT-REPAIR.md' --glob '!R6-LEGACY-PATH-REMOVAL.md'
~~~

预期：仅 `gateway-review-regressions.php` 对 Gateway 源文本做“不得包含旧符号”的反向断言；没有可执行引用。
