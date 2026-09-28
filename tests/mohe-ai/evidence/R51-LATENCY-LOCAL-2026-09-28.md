# R51 魔核 AI 本地问答耗时优化记录（2026-09-28）

本记录对应早期固定版本：当时点名门店问法仍有间歇性失败，**未达到完整验收，未提交、未部署、未推送**。后续修复与新版验收结果见 `R51-NAMED-STORE-ROLE-REPAIR-LOCAL-2026-09-28.md`；保留这里的失败样本，不以单次成功覆盖。

## 范围与版本

- 基线 HEAD：`4f637434531656ec1712c1208bb7a76e222f62d3`。
- 仅改魔核 AI 理解契约、模型提示去重与契约测试；未改指标、SQL、权限、事实层或客户端。
- 固定文件 SHA-256：
  - `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php`：`0ac773e360ee5f763b140f043034f51b1203a3e0ee5504449069862d7cd4282a`
  - `后端代码/app/services/ai/model/SiliconFlowClient.php`：`f17d9a766f05e8058f2d2f1af5a45b9079b6a5a50e7227b69f243b219ede5437`
  - `tests/mohe-ai/intent-understanding-contract.php`：`0075ab022d02696f30eb9961941133472c2513ba8935b45fe8bfe0a66f3bb9b6`
- 本轮补充／更新职责及边界注释：上述两个 PHP 业务文件；测试文件的说明注释。

## 变更

1. 首段理解提示与契约共用可接受字段名单，明确 `metric_terms` / `metric_exclusions` 属于 `values`，不属于 `fields`；仅对这类格式错误给一次有界修复提示，不替客户删改任何条件。
2. 将重复的排名说明集中到契约，删除一段重复的系统提示。未增加新模型、缓存、关键词映射或另一套业务查询。
3. 增加不含客户原句的字段形状诊断和回归测试。

## 验证

- `intent-understanding-contract.php` 179 checks PASS；`gateway-integration.php` 160 checks PASS；`personnel-analysis.php` 56 checks PASS；其他既有契约与查询测试通过，`git diff --check` 通过。
- 本地电脑端真人测试：先问“这个月现金业绩最高的是哪个门店”，2 秒返回合肥世纪中心店，run `25a1e7ebb0d5fbcf85f88b150d08312a431b463770f250bc`。
- 追问“这个门店技师做的单数最多的是哪个，最低的是哪个”，26 秒返回刘文秀 20.2 项、姚慧兰 0 项；run `f3cf835301d53271e2a3e49855ad6d9672dbb25cfddaed23`。后台证据为 `staff_project_num`、门店 1、`role:craftsman`、2026-09-01 至 2026-09-28、`top_and_bottom/1`；首段理解与绑定各一次成功，无模型修复调用。另一次同路径为 25 秒，run `6c8d5c5c78a9a7b494e64389f6fdc88af3e247e054063d39`。
- 再追问“今天呢”，1 秒返回当日暂无项目数记录；run `c39dfd13f781a9fc2f784fea61ffafd00fac8b651999f2d7`。后台证据保留同一门店、技师岗位、项目数和双向排名，仅日期换为 2026-09-28；只执行查询，无模型调用。
- 对照旧瑞昊样本见 `R51-RH-DEPLOYMENT-2026-09-28.md`：原追问 56 秒，其中首段理解约 18.4 秒、首段修复约 15.6 秒、绑定约 13.8 秒、查询约 1.9 秒。本地 25–26 秒只能说明该样本避免了首段重复调用；环境不同，不是正式线上性能承诺。

## 未通过与后续门槛

- 在同一会话点名门店追问“肥西水晶城这个门店技师项目数最多的是哪个，最低的是哪个”，run `a59cd2b74b5e2d28f01d145c59af862d4835e8bc7c89d960`，36 秒失败，`binding_requirement_delta_mismatch:object_kind`；重试 `662dc4a7609d7e6d4bb63e1ffb5086d05cf7786cf62f2b2f`，36 秒失败，`binding_requirement_value_mismatch:object_kind`。其他不同试验改动也出现对象／范围／结果形状失败，已撤回这些试探性代码，未混入本固定版本。
- 点名门店新会话曾通过但用的是当日无记录且默认参与者口径，不能算技师岗位验收；本月有数据问法在试验代码下失败。不能以自动化合同测试替代真人业务测试。
- 对匿名门店／岗位类型另做的一次提示试验，run `75a2301abf5ae9bbf216991b9b08f5684655f4fb1870c9c0`，47 秒后因预算耗尽失败；试验代码已撤回，说明“多发一点类型提示”并非已验证的优化。保留这个失败样本作为后续性能回归对照。
- 下一步应先隔离“匿名门店 + 匿名岗位”的模型语义与绑定冲突，建立稳定、非短语写死的测试，连续验证正确门店、岗位、指标、日期与耗时；通过后重新固定 SHA 并交产品经理确认验收。当前无提交、部署或 push 授权。
