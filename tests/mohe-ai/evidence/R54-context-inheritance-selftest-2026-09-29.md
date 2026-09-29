# R54 魔核 AI 上下文条件继承自测（待产品经理验收）

- 提交前基线 HEAD：`69bbb178530c97502d82ebf28b153e11db88b3f5`（相对原固定版本，仅新增其他任务的 R39 发布记录；本闭环四个代码／测试文件的 SHA-256 与补丁指纹均未变化）。
- 范围：同一已验证条件集合的金额、运算符、期间、人数／名单组合追问；人数结果追问客户信息时先按原条件取名单；多结果或指代不明时不默认选第一组。
- 本次变更文件：`后端代码/app/services/ai/AiGatewayServices.php`、`后端代码/app/services/ai/contract/AiIntentResultContract.php`、`后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php`、`tests/mohe-ai/condition-set.php`。未改数据库迁移、前端源码和部署配置。
- 注释：更新了上述三个后端文件里与已验证条件、人数无身份结果、多组结果边界有关的说明；测试断言解释了组合继承与拒绝条件。
- 旧代码治理：删除已被统一入口替代的 `inheritedConditionResultFormContextIntent`、`inheritedConditionUpdateContextIntent` 与各自网关调用；全路径引用扫描未发现遗留引用。其他仍有用途的日期、排名与详情路径保留。

## 验证

- 合成回归：`condition-set.php` 90 项、`context-delta-gateway.php` 92 项、`gateway-integration.php` 155 项、`gateway-review-regressions.php` 85 项、`object-detail-continuation.php` 35 项、`intent-understanding-contract.php` 225 项、`query-context.php` 64 项、`context-metric-rebinding.php` 7 项均通过。
- 三个改动的 PHP 文件语法检查和本闭环 `git diff --check` 通过。
- 本地 18081 真实工作台、更新本地常驻 AI 执行器后：条件人数 →“客户详情”成功返回同条件名单（8 秒）；“改为消费等于 980 元，只看本月，列出客户”成功按等于条件和本月执行（15 秒）；随后“把金额改成 4980 元，人数是多少”成功保留本月、切换人数和金额（11 秒）；再问“客户详情”成功返回同条件名单（8 秒）。只记录口径与耗时，不在证据中保存会员身份。
- 真人页面测试前发现常驻执行器仍加载旧 PHP，确认无活动任务后仅重启本地 `mohe-ai-execution-worker`，再执行上述有效测试；重启前的失败样本不作为新代码结果。
- 手机端未做实体机验收；两端调用的同一后端规则已做本地页面和合同测试。未部署、未 push。

## 固定代码指纹

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/ai/AiGatewayServices.php` | `1a53e5798ad1da226af4c6c694c87128d91c645538944d9ccd2b049131549ed1` |
| `后端代码/app/services/ai/contract/AiIntentResultContract.php` | `689e8231075e0f6230d5c7ad21ad4ac1c3325a4aa2fa4a3da1821a285dae02f3` |
| `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `60325c3468aac5fcf9e380ae12a9ce73f9b58dc378d2563bb8580cad0e41b793` |
| `tests/mohe-ai/condition-set.php` | `068f92d8052cb1d5654b3c644d15dd81aacb3ebd090a1dac97f8f16acbe3ade8` |

四文件补丁 SHA-256：`9ed3b42b489d6228fb65f6fd8c4dd5277647591fcca47b397824499d58395e62`。
