# R8F 会员补充回答后继续查询：本地固定版本与自测

- 基线 HEAD：`d4a264a3f7e9c9ca947ec9b842ed6cdcd85f9419`
- 范围：会员对象未确认时保留已选指标和时间，要求更正姓名或同名会员的完整手机号；仅在当前授权关系唯一匹配后读取该会员；答案显示后端授权读数中的会员姓名；Reader 契约不支持时说明能力边界。
- 文件清单指纹（按下列顺序对各文件 SHA-256 行再取 SHA-256）：`d64487bb2bf79e27e6a26c82b224b3524879f96053b3f8b32ece690c22ab1f09`

| 类型 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/ai/AiGatewayServices.php` | `c810fb96416a999091b7e1810eaef6ea3b811db470e0b43be12d5c76dce411bb` |
| 后端源码 | `后端代码/app/services/ai/presentation/AiAnswerRenderer.php` | `48ce0ea460896db94138096d239a185e9b8ea053d931ee393b05ecb228ad2b1d` |
| 自动化测试 | `tests/mohe-ai/r8f-member-identity-continuation.php` | `76528f83f0afe75e96d656829ce1e929b52c6abc16e61ea071126ee27a4d2dca` |

前端源码、数据库迁移、工程脚本、生成物：无。新增／更新注释位于两份后端源码及新测试文件，解释未确认身份不得降级为人群汇总、已选条件冻结、结果对象标识来源和契约失败边界。

## 实测

1. 本地 `18081` 工作台实际提交“管慧的业绩是多少？”，按卡片补充“管慧是会员”，选择“实际收款销售额”。页面从原来的查询失败改为第 3 步“确认会员”，说明当前授权范围不能确认该姓名。
2. 在同一问题卡更正为本地授权范围内唯一的 `Abby` 后，页面约 1 秒完成查询，显示“实际收款销售额为 0 元”，统计时间为 2026-10-16。此步在结果对象标识补丁加入前完成，证明补充回答能继续执行，而不是重新提问或把未确认会员当作零值。
3. 随后加入“会员‘授权姓名’”结果标识。使用真实 Reader 形状的合成 Run 测试确认答案取自签名读数的 `member_selection_label`；未确认或再次输错姓名时不读取事实，改对后同一 Run 只读取被授权会员一次；本月日期和已选指标保持不变。
4. 标识补丁后，本地另两次实际输入“会员Abby在本月实际收款销售额多少？”均停在模型理解阶段，分别约 62 秒和 79 秒返回 `AI_MODEL_RESULT_UNKNOWN`。随后新建对话，原问题与“管慧是会员”再次进入第 2 步指标卡，但选择指标后约 45 秒也由模型理解阶段超时。因此补丁后的最终页面标识仍需在模型恢复后再作一次真人复核。此限制不影响第 2 步已完成的同轮补充查询证明；不能据此宣称当前模型链路稳定。

## 回归

- `r8f-member-identity-continuation.php`、`opt1-member-guidance-gateway.php`、`member-analysis.php`、`r8e-unresolved-named-performance-guidance.php`、`r63-failure-guidance.php`、`query-read-view.php`、`semantic-guidance.php` 均通过；后两项分别为 65、103 条合成检查。
- PHP 7.4 容器对两份后端源码语法检查通过；本机对三份文件语法检查与 `git diff --check` 通过。
- 引用扫描确认原同名手机号处理和 `AI_QUERY_FAILED` 仍分别服务于当前的身份判定与运行时失败，未发现可安全删除的旧业务分支；已替换旧的“所有 Reader 契约错误均归为可重试查询失败”路径，未保留平行实现。

状态：开发与自测完成，待产品经理确认此固定版本验收。未 commit、部署或 push。
