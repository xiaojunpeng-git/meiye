# 第 7 轮最终方案验收：上下文范围清除修复

日期：2026-09-12。结论：**本次发现的上下文范围漂移已修复，代码与完整本地回归通过；第 7 轮仍缺真实三入口对账、独立留出题及培训交接，因此不能标为产品验收完成。**

本报告是代码与本地测试复核，不替代产品经理验收，不连接客户数据库、真实模型或生产环境。

## P3：只改日期时错误清除门店范围（已修复）

风险场景：用户已限定一家门店，随后只说“改成 9 月 9 日”。如果绑定模型错误给出 `context_delta.store_scope=clear`，旧合并逻辑会把已签名的单门店范围移除；Reader 随后会按当前账号的全部授权门店查询。这不是账号越权，但会扩大用户未要求的业务范围。

修复位置：`后端代码/app/services/ai/contract/AiIntentResultContract.php`、`后端代码/app/services/ai/execution/IntentContextMerger.php`。

- `store_scope=clear` 仅接受**当前这句话**已理解并明确表达的“全部/授权门店”范围，且必须同时替换 `scope=authorized`；历史消息中的“全部门店”不能解除后来已缩小的范围。
- `store_scope=replace` 仅接受当前轮已理解为门店对象的请求。
- `business_filters` 的 `clear/replace` 同样必须有当前轮已接受的对象需求来源。
- 合并器会把解除已确认门店范围保留为待确认状态：模型可以理解用户想扩大范围，但在用户选择“改为当前全部可查看范围”前，Reader 仍使用上一轮签名范围。没有增加中文关键词分支，也没有将日期续问绑定成固定问法。

## 隔离复现与修复后回归

运行：`php tests/mohe-ai/r7-context-removal-audit.php`。

脚本实际经过 Gateway、理解/绑定合同、上下文合并与 Reader 流程，使用内存 SQLite、合成事实和固定模型输出；账号权限为 `[1,2]`，前文已确认的门店范围为 `[1]`。

| 用例 | 本轮含义 | 绑定 delta | 修复后结果 | Reader 事务 |
|---|---|---|---|---|
| 正常续问 | 仅改为 9 月 9 日 | `inherit` | 完成，查询门店 `[1]` | 1 |
| 伪造清除 | 仍仅改日期 | `store_scope=clear` | `FAILED / AI_MODEL_INTENT_CONTRACT_INVALID` | 0 |
| 历史误用 | 历史消息说过“全部门店”，当前仍仅改日期 | `scope=replace`、`store_scope=clear` | `FAILED / AI_MODEL_INTENT_CONTRACT_INVALID` | 0 |
| 证据错用 | 将“9 月 9 日”的摘录同时写为日期和全部门店依据 | `scope=replace`、`store_scope=clear` | 进入门店范围确认；确认前为 0，选择保留后仍查 `[1]` | 0（确认前） |
| 明确扩大 | “改为全部门店，9 月 9 日” | `scope=replace`、`store_scope=clear` | 先呈现范围确认；用户确认后 Reader 收到 `[]` 并按当前授权范围绑定 | 0（确认前） |

输出为 `AUDIT_FAILURES=0`。无来源范围变更仍由结构合同阻断；解除已签名门店范围则由服务端确认步骤阻断，模型复核结果不能绕过该步骤。用户当前明确要求全部授权门店时，确认后仍能正常执行。

## 最终方案完成度

| 要求 | 当前结论 |
|---|---|
| 理解与绑定分离、两份步骤式 Skill | 已有正式实现；本次未发现正式问数回退到旧关键词解析器 |
| 登记指标与统一 Reader 取数 | 已有实现；本次未新增平行事实来源或经营公式 |
| 人员结果引用与本人权限候选 | 既有针对性回归通过 |
| 执行限定不得无依据扩大、清除或替换 | 本次 `store_scope`、`business_filters` 保护已修复并回归通过 |
| 日期边界单一来源与候选确认 | 现有日期回归通过；本次未改变业务日期约定 |
| 三入口真实权限、Reader 及同条件报表对账 | **仍缺完整证据**；本人权限无旧页面时，应以权威权限范围和 Reader 直查为基准 |
| 独立留出题与分类验收 | **仍缺**独立来源留出集；旧 R5 夹具不能冒充本轮真实模型留出验收 |
| 本轮培训交接 | **仍缺**本人权限可用范围、三入口一致性和“本店”指代变化的交接材料 |

## 代码质量与测试

- `php tests/mohe-ai/query-context.php`：42 项协议/上下文检查通过。
- `bash tests/mohe-ai/run-all.sh`：退出码 0；已纳入本修复的 `r7-context-removal-audit.php`。
- 容器 PHP 对修改后的合同文件语法检查通过；`git diff --check` 通过。
- 指标硬分支扫描 `rg '\$metric ===|switch \(\$metric' app/services/query app/services/ai` 无输出；旧去重写法扫描 `rg 'distinct\(true\)->count\(' app` 无输出。
- 宿主 PHP 8.5 的 ThinkPHP 依赖弃用提示仍存在，但未造成测试失败。

全量回归为本地合同、合成事实、SQLite 状态机、前端模拟及静态检查，不能代替真实模型、真实数据、三入口业务对账或产品经理最终验收。未提交、部署或推送。
