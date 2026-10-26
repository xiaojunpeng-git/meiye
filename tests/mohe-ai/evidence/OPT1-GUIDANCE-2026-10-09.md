# OPT1｜魔核 AI 智能首答与按需引导：本地开发自测

本文件记录首次自测及其当时未通过的结果；修复后的固定版本、真实页面重测和当前状态见 `OPT1-REPAIR-2026-10-09.md`。原验收失败事实见 `OPT1-ACCEPTANCE-2026-10-09.md`。未提交、未部署、未 push。

## 固定版本

- 本轮开始时 HEAD：`b8c83466b5facb7e1dc283c9da15176b57121938`；固定交付时共享工作区 HEAD：`b9547566d0a35db9fdff83e8c84fa77eefbc3fa5`（期间其他任务提交，与本闭环文件无重叠）。
- 本闭环已跟踪源码及测试的补丁 SHA-256：`ef0c310689be20c210202b7b2bfc8581cbe444fce169a296404c3abb60f318b6`；新增测试单独列文件 SHA-256。
- 工作区有其他任务的已暂存与未暂存改动。本闭环仅以以下文件为验收边界；后续任何修改须重新固定指纹。

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/shared/mohe-ai/browser-entry.mjs` | `85c8ce7662d13a9fe5117851c11f53f64c675176de7792055ca7564416ebdf6d` |
| 后端源码 | `后端代码/app/services/ai/AiGatewayServices.php` | `a36cb525c8a25a51b0e29a8ab609dfe0b88523aa429c54bf7a129b73d057f447` |
| 后端源码 | `后端代码/app/services/ai/execution/AiAnalysisGuidancePlanner.php` | `f236ea53ebe274d8b8ba8b9e021fba6806c0652106e9875d0c5e2558e3e9df5b` |
| 后端源码 | `后端代码/app/services/ai/execution/AiDimensionGuidancePlanner.php` | `b055fa308476ffb060cfc700c89ff55cab17645cdccdf85581fee6a479d9b242` |
| 后端源码 | `后端代码/app/services/ai/execution/AiSemanticRecommendationPlanner.php` | `85afb5060e3a9fc7a9d8733f15f11f5a85ec4fc6dc06cf68b5314696c3917c8f` |
| 后端源码 | `后端代码/app/services/ai/execution/AiWorkflowPlanner.php` | `8d02e375ae58a29802516f344ef61ff9ae64da636a0925b70d15bc5d1e9b143f` |
| 后端源码 | `后端代码/app/services/query/metric/MemberAnalysisObjectServices.php` | `7593bf40df2e3797ba3e8b03f8465ae3704ec66349be2d0cac6f70e3349fd931` |
| 自动化测试 | `tests/mohe-ai/browser-guidance-contract.mjs` | `e81f910889738bd1ad0a0f2e9e6b194772e3227c12b4082a381707d5638049a4` |
| 自动化测试 | `tests/mohe-ai/member-analysis.php` | `2b83dfdc6b7185f8889b6c0871a32cc41c335695e3416c2a402160a10317f4e0` |
| 自动化测试 | `tests/mohe-ai/semantic-guidance.php` | `bcd1e9966a59582c727f5f6d06e3364222f0601a7c3f4927b5a91b8214885745` |
| 自动化测试 | `tests/mohe-ai/opt1-guidance-gateway.php` | `b5971ec89c1210c652d3f62c2d8f873e8f1edf69007f504ba70cfa7a10c2d0c6` |
| 后端源码 | `后端代码/app/services/ai/model/AiSafeQuestionProjector.php` | `8dbcbd27d7b4ea34661f09d9da1c2c4192c78449d2f05c7d5caa729d5c909af3` |
| 后端源码 | `后端代码/app/services/ai/model/SiliconFlowClient.php` | `0e67a654ae14797012b3a4648ff7a234db8da0b188d4f212b3c79c2f6473f7f2` |
| 自动化测试 | `tests/mohe-ai/gateway-components.php` | `09509a7ac7012d4647150b79fd483420b2f6b20f04276b1170aff11ac9425564` |
| 自动化测试 | `tests/mohe-ai/gateway-integration.php` | `e048fc9ac1c66cbf1ab94bb415c70b6ebc7d4e1da67dfef94b430c35e1470030` |
| 自动化测试 | `tests/mohe-ai/opt1-member-guidance-gateway.php` | `f96a48a00cfe140d944b4eecf903063b78a860331dca3a75477f902faf55c45e` |

数据库迁移：无。工程脚本：无。生成物：本地执行 `前端代码/admin/` 的 `npm run build`，生成忽略的 `dist/`，这不是 8080 集成包，也未发布。验收证据：本文件。

## 本轮行为

1. 平台端完整问题继续直答；缺少指标且缺少期间的宽泛问题，在现有 Run 中先确认指标，再确认时间。模型先判“需要澄清”时，已预检的指标建议也接续日期步骤，不把预检用的“今天”变成用户条件。有效的签名上下文继续优先于新默认值。收银端既有卡片呈现通过终端标志保留。
2. 平台端引导卡片只强调模型推荐且后端预检可执行的选择。推荐不足时不补“重查／改写／新对话”凑数；无推荐而注册指标较多时使用中立下拉列表。无安全推荐时只留补充问题入口。
3. 同名会员先按授权范围识别并遮蔽姓名，模型确认会员意图后再本地绑定。多位同名时在同一 Run 提示补充完整手机号；姓名、手机号均由本地投影遮蔽，手机号仅用于受权限约束的精确绑定。匹配失败可核对重填；匹配仍不唯一不猜测身份。
4. 平台端技术失败只显示简短说明，不再附带与失败原因无关的四选项卡。无业务意图和能力变更仍使用原有能力说明。
5. 删除了被新遮蔽／精确绑定路径取代、已无生产引用的 32 人会员预扫描方法及对应旧测试入口。修改过的业务分支注释同步说明权限、上下文和默认值边界。

## 自测结果与限制

- `php tests/mohe-ai/member-analysis.php`：32 项通过（合成会员数据，含同名、手机号、遮蔽）。
- `php tests/mohe-ai/semantic-guidance.php`：106 项通过（含宽泛问题指标后仍需时间）。
- `php tests/mohe-ai/opt1-guidance-gateway.php`：11 项通过（同一 Run 的指标→日期→执行、门店问题按需引导、完整问题首答、模型判为无业务意图时仅能力说明）。
- `php tests/mohe-ai/recommendation-planner.php`：127 项通过（真实注册表和编译器，未读业务数据）。
- `php tests/mohe-ai/context-delta-gateway.php`：102 项通过（签名上下文与续问）。
- `php tests/mohe-ai/analysis-object-resolution.php`：16 项通过。
- `php tests/mohe-ai/skill-semantic-projection.php`：21 项通过。
- `php tests/mohe-ai/r63-failure-guidance.php`：通过。
- `node tests/mohe-ai/browser-guidance-contract.mjs`：201 项通过（平台失败无填充卡、原抽屉路径回归）。
- `node tests/mohe-ai/browser-mobile-guidance-contract.mjs`：218 项通过，另两组 UI 契约通过。
- PHP/JS 语法检查及 `git diff --check` 通过。
- 平台 `npm run build` 完成；仅有既有资源体积和 Browserslist 数据陈旧警告。
- `php tests/mohe-ai/gateway-integration.php`：183 项通过。验收中证实旧夹具未执行退款负数过滤，错误向正收入累计额外 100 元；已修正夹具，原 323 元断言保留。另将三处旧恢复卡断言按现有门店兼容行为修正，仍严格验证不产生业务建议或数据查询。此前把失败归因于其他任务指标改动的判断证据不足，本条予以更正。
- 已补充 18081 真实模型及浏览器验收；业务数据仅读取本机 MySQL。未做生产验证、真实会员手机号查询或收银页面验收。最终结论为未通过，不能用合成测试替代。

## 验收期间补充的修复

- `AiSafeQuestionProjector.php` 将敏感片段替换改为单遍，防止已生成的脱敏标记再次匹配长标识符规则，保持手机号续接引用稳定。补充真实职责及边界注释。
- `SiliconFlowClient.php` 明确理解阶段不能仅因值已脱敏而标为 unbound，绑定阶段应保留不透明标记，禁止恢复私有值；删除“禁止输出标记”与对象绑定相冲突的旧提示。同步更新边界注释。
- `opt1-member-guidance-gateway.php` 通过合成姓名、手机号验证同名澄清、同一 Run 补充身份、一次查询及模型输入无明文身份。此测试使用明确指标，不代表原始“消费多少”问句已经通过。
- `gateway-components.php`：147 项通过；`gateway-integration.php`：183 项通过；`intent-understanding-contract.php`：263 项通过。
- 本次没有新增 Skill、数据库迁移或业务事实写入；无用会员预扫描路径删除结论保持，新增的是永久回归测试，不维护第二份源码。
