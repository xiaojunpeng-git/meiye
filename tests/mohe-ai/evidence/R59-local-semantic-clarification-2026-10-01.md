# R59 本地语义澄清自测（待产品经理验收）

## 2026-10-02 推荐可执行性与续查耗时修复（未提交、未部署）

本节是阶段提交 `f23a335c` 之后的追加修复，不覆盖下方历史失败。工作区当前 HEAD 为 `0ccc6bd1fbf484927646f324424b7b3b3b53c5cf`，包含其他任务提交；本次不修改或暂存其他任务文件。

### 实现边界

- 推荐候选从已发布对象/指标能力生成，使用实际 `AiRegisteredPlanCompiler` 做无取数预检；不对三个推荐分别做模型绑定或 SQL 查询。
- 一次短模型请求只选择三个服务端候选 ID；候选显示名称、对象、指标、形态和完整日期由服务端保存，模型不能替换它们。用户点击后复用私有候选，在原 Run 中执行，仍检查最新权限、配置和能力，查询前重新编译。
- 删除“点击推荐后将显示文字重新提交理解/绑定”的旧分支及从理解模型字符串直接生成可执行选项的分支。历史理解载体的字符串读取兼容保留，但不再生成或执行这些字符串。
- 自由补充继续走自然语言理解；原日期/对象未修改则保留，当前明确修改优先，新话题不继承。单查询的签名上下文完整保留私有门店范围、业务筛选和期间；缺失话题判断不能静默去掉原范围。
- 分析对象、选定对象、指标排除、复合条件不混淆。无法完整保留的条件、集合上下文或不足三个可执行候选不会被删掉来凑数，而是只保留补充入口。这是尚未满足所有场景四选项的已知边界，不称为全量通过。

### 本地真实电脑页面复测

入口仍为 `http://127.0.0.1:18081/admin/setting/mohe-ai`，真实模型、接口和数据库，不是夹具。三次分别从新对话发问“这个月会员那个金额的情况”；模型选出的三个选项均为当前注册能力，没有写死这句测试问法。

| 操作 | 实际结果 | 耗时及诊断 | 判定 |
|---|---|---|---|
| 第一推荐：会员现金业绩逐个明细 | 本期39位会员，合计75,243元，完整日期10月1日至10月2日，预览20条并有Excel入口 | 页面1秒；选择段客户端1109ms；查询187ms；仅最初理解和推荐排序共2次模型调用 | 推荐可执行性、保留期间、选中后不再调用模型通过 |
| 第二推荐：会员实际收款销售额逐个明细 | 本期25位会员，合计55,404元；完整日期不变，有Excel入口 | 页面1秒；选择段客户端1299ms；查询67ms；模型调用仍为2次 | 此候选明确标注了不同指标，没有将它静默替换成现金业绩；点击通过 |
| 第三推荐：会员现金业绩最高20名 | 返回最高20名，完整日期不变 | 页面1秒；选择段客户端1327ms；查询176ms；模型调用仍为2次 | 通过 |
| 九月原句＋第四项补充“按现金业绩，列出每个会员” | 同Run返回“本期没有产生现金业绩的会员”；已核对服务端查询为member、cash_performance、breakdown、9月1日至9月30日、无额外筛选 | 到卡片15799ms；补充后页面19秒，理解9899ms、绑定8110ms、查询142ms | 原期间继承和补充流程通过；未做人数独立SQL对账；两个主动处理段合计约35秒，仍超过25秒目标 |

前三个卡片的理解／推荐排序分别为9055/4046ms、6944/1439ms、6120/1138ms。点击前人工等待不计入模型/查询耗时；不将约1秒点击耗时宣传为整次理解仅1秒。

另一次请求约3分钟失败：请求创建于1790886475026ms、执行开始1790886475330ms，本地执行容器于1790886476300ms重启，恰好中断理解请求；最终reason=DEADLINE_EXCEEDED、attempt=UNKNOWN。该中断记录保留，不冒充正常模型耗时，也没有因它去缩短成功查询的条件或超时窗口。

### 自动化验证与旧测试替换

- 推荐预检70、网关集成178、上下文续查102、管理网关36、网关组件141、理解契约241、理解技能投影21、状态/attempt76、查询上下文67、会员详情39、对象详情78、语义引导104、网关审查85、管理核心47、能力目录21、绑定对象边界6 checks PASS。
- 电脑DOM/HTTP契约180、小程序控制器降级夹具193 checks PASS；这不是手机真人验收。
- 新测试逐个选中三个推荐，断言执行成功、不新增模型调用、完整原日期不变；覆盖签名私有范围、话题切换、缺失话题判断、私有对象筛选不得被改成无条件人群，以及伪造/重复候选ID。
- 原部分自然语言测试不再假设“选指标→选时间”旧入口；改为统一语义卡与明确补充完整期间/指标。仍有用途的底层绑定/管理版本冻结测试保留并明确与自然语言卡区分，不为获得PASS删除权限或日期断言。
- PHP语法和 `git diff --check` PASS。部分独立测试首次直接运行缺少fixture自动加载，按run-all的 `auto_prepend_file` 方式重跑通过；没有将启动错误记成业务失败。完整run-all本次未重跑，历史冻结R5 holdout差异仍未宣称解决。

### 文件分组与交付状态

- 后端源码：`AiGatewayServices.php`、`contract/AiIntentUnderstandingContract.php`、`model/SiliconFlowClient.php`、新增 `execution/AiSemanticRecommendationPlanner.php`、`skills/intent_understanding/SKILL.md`。
- 永久自动化测试：`gateway-integration.php`、新增 `recommendation-planner.php`、`context-delta-gateway.php`、`management-gateway.php`、`r6-gateway-harness.php`。
- 工程测试入口：`tests/mohe-ai/run-all.sh` 加入推荐预检测试。
- 前端源码、数据库迁移、构建生成物：本次无修改。本文件是证据，不是业务源码。
- 上述11个文件按本节清单顺序执行 `shasum -a 256`，再对输出执行SHA-256的清单指纹：`3e12bd24838ac5e025d41572f1d456eb363debdd78920e294b7464c86f9cf523`。
- 职责/输入输出/权限/上下文/统计边界注释补充在网关、新候选规划器、模型请求及相关测试；没有改收银、生产配置、模型供应商或密钥。

上述候选阶段的两个原推荐失败已在本地三选项真实复测中消除，但当时仍有自由补充总处理约35秒、复杂条件不足三个可信候选的覆盖边界。

### 追加：明确补充不再二次绑定（同日）

- 自然语言理解接受了完整对象、形态和唯一注册指标后，复用同一候选编译器完成无取数预检；恰好一个完整可执行计划才直接查询。不是扫描“列出”等关键词来猜操作，也不绕过权限、私有对象绑定、复合条件或普通语义复核；这些不满足闭合条件的输入仍走普通路径。
- 同一句九月问题、同一句第四项补充真实复测：日期仍为9月1日至9月30日，返回同样的空现金业绩名单。服务端已无 `bind_intent_reply1`，补充理解17141ms、数据查询160ms，页面18秒。首次理解17341ms、推荐排序1596ms，模型调用总数由4次降为3次；两段主动处理合计约38秒，仍未达25秒目标。模型波动不能用调用次数减少掩盖。
- 网关集成新增完整已接受补充的预检、原日期/对象保留、不调用模型/事实、私有对象不得绕过绑定，更新至180 checks PASS。上下文102、管理网关36、候选70、组件141、审查85、会员详情39、对象详情78、理解241、状态引导92、状态/attempt76、技能投影21、两端控制器180/193回归重新通过，语法与diff检查通过。
- 该追加仍仅修改上述同一11文件。最新文件清单指纹为 `0b5c7c66d473bdf800ef3831f9cd565a7c9d95deee725057000f314addc0bcd1`，覆盖前述候选阶段指纹，历史结果不改写。

当前状态：推荐可执行性与推荐点击的二次模型耗时已在本地消除；自由补充仍存在整体耗时波动，复杂条件不足三个可信候选仍是覆盖边界。本次未commit、未部署、未push。小程序按既定要求仍连接瑞昊线上，当前新后端未上线，因此不能写“两端真人通过”，也不以旧线上代码作为本次验证。待这些边界处理/确认及固定版本验收后，再按独立发布授权执行。

- 基线 HEAD：`a242ea3c130b5fe89f5ebf4c80874bc24b0e95df`；本轮未 commit、未部署、未 push。
- 本轮最新补丁指纹（仅下列 11 个源码/测试文件，含 10 月 2 日错误提示修复）：`5745f638fbdbff42d7d0f9ff928c24acc56f4ad934941872f652d5156114defb`。
- 后端源码：`AiGatewayServices.php`、`contract/AiIntentResultContract.php`、`execution/AiWorkflowPlanner.php`、`execution/AiRunStore.php`。
- 前端源码：`shared/mohe-ai/browser-entry.mjs`、`mobile-vue3/src/shared/components/mohe-ai-entry.uvue`。
- 自动化测试：`gateway-integration.php`、`gateway-components.php`、`intent-understanding-contract.php`、`browser-guidance-contract.mjs`、`browser-mobile-guidance-contract.mjs`。
- 数据库迁移、工程脚本：无。小程序本地编译产物是生成物，不纳入源码清单。

固定文件 SHA-256（均相对 `美容源码/`）：

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/ai/AiGatewayServices.php` | `54c33bfda9d39cc16608be007c0e1abdc0171a98513fcdcf09ccc94185a21e19` |
| `后端代码/app/services/ai/contract/AiIntentResultContract.php` | `3e5424ffe7cdc52d97ab68ea92bbe5e9be0e570cf9abffdc3463ddd2ad445331` |
| `后端代码/app/services/ai/execution/AiWorkflowPlanner.php` | `4904dbca177be67b9eedb5977b69eaba6eef499b7d6077e651cdcfdfcf996d21` |
| `后端代码/app/services/ai/execution/AiRunStore.php` | `9be99876776b4143154568af4b821b66f170e1133406224569dfdfb1a41e7bd2` |
| `前端代码/shared/mohe-ai/browser-entry.mjs` | `d86f3ff9a68e477d5d8a464d6c4bcd1cb2db65783b9095d54002cb52c9332e75` |
| `前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue` | `b616606c115272b7b72d9487ea8460ba4b0a96a04ff941e07f8aab995007ae05` |
| `tests/mohe-ai/browser-guidance-contract.mjs` | `31a9967c3867442ab53f514e7c3f01fa6f29f697aba71dae3d05bb59e65d7e45` |
| `tests/mohe-ai/browser-mobile-guidance-contract.mjs` | `c65d22657c217c1caa6d2fca5156d997d83676cc648d75cc76f05f6c35e234dc` |
| `tests/mohe-ai/intent-understanding-contract.php` | `22fd583f7a7eb2ebbb6c9f543bccbeffe441addb568857c75f62d278f2cfd26d` |
| `tests/mohe-ai/gateway-components.php` | `e77ccf743ae0ed3d4680292fbfb88200c6bba5aadeced658aa418548f86b39b5` |
| `tests/mohe-ai/gateway-integration.php` | `74e728baff7fe76827743bb68693980ab08c04bcdc6bbe023550bab72af193d6` |

## 口径与边界

- 可执行的清晰问题仍直接走原查询；未增设模型调用。模型确需确认指标时，最多展示三个经当前注册目录核验的模型候选，再提供“我来补充描述”。没有可信候选时不按目录顺序凑数。
- 用户补充描述后，原问题与补充文字作为一个新问题重新理解；先取消待确认的旧 Run，确认取消成功后再发问。补充内容不能作为指标代码或跳过权限、Reader 核验。
- 完全无法理解、部分理解、能力未接入、技术错误使用不同说明。部分理解但没有可靠候选时显示补充卡；不会伪造三个解释。此时尚不能保证达到“总有四项”的视觉数量，属于准确性优先的有意边界。
- 新增/修改注释覆盖上述语义候选、取消重试、业务失败分类及前端补充边界。旧 `other_registered_metric` 仍由服务端兼容选择和既有测试引用，不属于本轮可删除死代码。

## 本地验证

- `php tests/mohe-ai/intent-understanding-contract.php`：230 checks PASS。
- `php tests/mohe-ai/gateway-components.php`：141 checks PASS。
- `php tests/mohe-ai/gateway-integration.php`：158 checks PASS（SQLite、模型与事实夹具）。
- `php tests/mohe-ai/semantic-guidance.php`：104 checks PASS。
- `php tests/mohe-ai/state-guidance-contract.php`：92 guidance checks PASS。
- `php tests/mohe-ai/management-gateway.php`：36 checks PASS。
- `node tests/mohe-ai/browser-guidance-contract.mjs`：174 checks PASS；`node tests/mohe-ai/browser-mobile-guidance-contract.mjs`：190 checks PASS。
- `node tests/mohe-ai/browser-workflow-contract.mjs`：34 checks PASS；`node tests/mohe-ai/browser-transport-contract.mjs`：10 checks PASS。
- HBuilderX 5.26 `launch mp-weixin --compile true`：本地小程序编译成功；不是上传或实体手机验收。
- `git diff --check` 与改动 PHP 文件语法检查通过。
- `run-all.sh` 在旧 `r5-holdout.php` 的既有范围判定差异（27 matched、13 mismatched，包含 H022 wrong_hit）退出；该夹具自己标记多项 `SCOPE_REVIEW`。本轮没有把该结果冒充完整回归通过。

未运行真实模型、生产数据或实体手机验收；耗时小于 25 秒尚无真人样本证明。待产品经理对本固定版本验收后，才能进入本地 commit；部署、push 和体验版仍需分别授权。

## 2026-10-02 复测

- 重新计算上列 11 个文件的 SHA-256，均与固定版本一致；本轮没有修改业务源码。
- 上列 10 个相关 PHP/JS 契约与集成命令全部重跑通过；`git diff --check` 通过。测试仍为 SQLite、合成模型/事实与 DOM/HTTP 夹具，不等于真实模型或实体手机验收。
- 在 `http://127.0.0.1:18081/admin/setting/mohe-ai` 新开独立本地测试页，实际打开工作台并提交“今天的现金业绩是多少？”。页面显示原始错误码 `AI_NOT_CONFIGURED`，同时该测试页基础配置显示“启用魔核 AI”及外部处理授权均为关闭。因此本次没有进入意图理解或查数阶段，不能据此判断清晰问题、问题卡及耗时的真实表现；未擅自启用配置。原始错误码直接给用户看也不符合本轮的易懂提示目标，列为待修问题，不能把浏览器实测标记通过。
- 完整 `run-all.sh` 本日重跑退出码为 1，停在冻结的 `r5-holdout.php`：40 例中 27 匹配、13 不匹配，其中 8 例被夹具标记为 `SCOPE_REVIEW`，H022 为 `wrong_hit`。该脚本直接调用旧投影/编排夹具并把时钟固定在 2026-09-09，不是 R59 真实模型端到端测试；但红项未消除，不能声称完整回归通过。

### 数据架构自检

本轮只改意图契约、澄清展示与失败提示，没有新增或改写业务事实、统计指标、筛选时间、金额算法或导出。权威数据仍由既有统一指标 Reader 与后端权限链路提供；事实粒度、成功生效时点、四类时间、事务/幂等/Outbox、退款/冲销、历史快照、索引与对账均沿用原实现，本轮不产生新写入或迁移。补充描述重新进入同一后端理解与权限校验流程，前端不计算指标也不扩大范围。性能上没有增加模型调用；真实端到端耗时尚未测得。

## 2026-10-02 错误提示修复与复测

- 问题：本地 AI 未启用时，电脑端直接向用户显示 `AI_NOT_CONFIGURED`；小程序在入队前配置变化时也会透传同类错误码。
- 修复：仅在双端展示边界把已知配置、登录、权限、页面版本错误转为可操作的中文提示；其他 `AI_` 机器码使用通用服务提示。服务端错误码、权限检查和查询口径不变；未开启模型、未修改外部授权。改动涉及 `browser-entry.mjs` 与 `mohe-ai-entry.uvue`，并在两份前端契约测试中增加对应回归。注释已补充错误码和用户提示的职责边界。
- 电脑端 DOM/HTTP 夹具：180 checks PASS；小程序控制器夹具：193 checks PASS。意图理解 230、网关组件 141、网关集成 158、浏览器工作流 34、传输 10 checks 均通过；`git diff --check` 通过。
- HBuilderX 5.26 重新编译 `mp-weixin` 成功，属于本地编译，不是上传或真机验收。
- 本地 `mohe-admin-src` 重新编译成功后，实际在 `18081` 工作台提交“今天的现金业绩是多少？”；页面展示“魔核 AI 暂不可用，请管理员检查基础配置中的启用状态和外部处理授权。”，不再展示原始错误码。该环境配置仍为关闭，故真实模型回答、问题卡和耗时仍不能据此验收。
- 完整 `run-all.sh` 的冻结 `r5-holdout.php` 仍有前述 13 条不匹配；本次未修改其业务预期。没有 commit、部署、push 或上传体验版。

## 2026-10-02 授权配置与真实模型自测（未通过闭环）

产品经理确认后，仅在本地 `18081` 保存客户提供的测试密钥，开启 AI、SiliconFlow 外部处理授权及最小脱敏问题授权。模型保持 `Qwen/Qwen2.5-72B-Instruct`。页面确认“基础配置已保存”，密钥输入清空；连接与结构化响应测试通过。没有修改瑞昊线上配置，密钥不写源码或本记录。

| 实际页面问题 | 结果 | 页面耗时 | 判定 |
|---|---|---:|---|
| 现金业绩是什么意思？ | 返回登记的收款来源、计入口径与退款区别 | 14 秒 | 提示与解释路径通过；不是金额对账 |
| 同会话：这个月业绩怎么样？ | 返回实际业绩及完整期间 | 26 秒 | 能答，超过 25 秒，保留性能问题 |
| 新会话：这个月业绩怎么样？ | 返回本期经营概览、多项登记指标和期间 | 19 秒 | 能答；不是全部指标对账 |
| 新会话：这个月门店业绩最高的是哪个？ | 返回现金业绩第一名、金额和本月期间 | 1 秒 | 能答 |
| 新会话：这个月会员那个金额的情况 | 部分理解提示＋补充输入框 | 14 秒 | 未凑错指标，但未满足三个推荐＋一个补充 |
| 上问补充：我想看会员本月的现金业绩 | 完整原问与补充文字重新提交，回答“当前暂不支持这种分析方式” | 19 秒 | 重提流程生效，但仍未给出业务答案，不算闭环通过 |
| 蓝色的咕噜把月亮折起来 | 仍被分类为部分理解并展示补充卡 | 11／13／12／15 秒，另一次 72 秒 | 错误分类，长耗时样本也记录；未宣称修复通过 |
| 咕噜咕噜叽里呱啦 | 返回底层组合不支持说明 | 16 秒 | 无业务含义却被当成能力缺口，未通过 |
| 修复后刷新页面：？？？？？ | “我还没理解您想查什么。我可以帮您查询今天的现金业绩、消耗业绩……” | 3 秒 | 该样本通过；不能外推其他无关句子全部通过 |

### 本次源码修正及验证

- 理解契约与源内意图理解说明明确：没有可识别业务含义时使用空 requirements，不能把任意名词、动词硬套成业务条件。
- 服务端直接在“模型已接受空理解”时停止，不再让后续目录修正或第二次绑定凭空构造查询；节省这一状态下的额外模型调用。非空理解的失败分类改用已接受的结构化业务条件，而非简单判断 requirements 非空。不改变取数、权限、指标或金额。
- 新增显式开关保护的 `r59-local-synthetic-meaning-probe.php`，只读取已加密本地配置、发送固定合成句，输出合成语义结果和 tokens，不输出任何配置密钥。独立模型诊断返回 requirements=[]、needs_clarification；与页面中的误分类有差异，完整链路仍须继续定位，不能将独立调用成功代替页面通过。
- 修改代码和源内说明均补充了职责、输入边界及禁止伪造业务条件的注释；没有新建平行业务实现。本次替换原有三处分支中的“非空 requirements 即部分理解”判断，旧判断不保留。
- 最新回归：理解契约 234、网关集成 159、网关组件 141、管理网关 36、语义引导 104、状态引导 51＋92、浏览器 180、小程序控制器 193 checks PASS；PHP 语法检查与 git diff --check 通过。仍非实体手机验收，完整 run-all 的前述红项未消除。

### 最新候选版本（替代本记录前列源码指纹）

基线 HEAD 不变。tracked 文件补丁 SHA-256：`25bc2c3714aa76e2fe45c1ea08596e9044f2855eabc66efc8577b98a0a01e917`；未跟踪诊断脚本单列内容 SHA-256，不把 git diff 的覆盖范围写大。

以下文件内容已变更，其余原列文件 SHA-256 不变：

| 文件 | SHA-256 |
|---|---|
| 后端代码/app/services/ai/AiGatewayServices.php | 5b8409d557fe053b74a13eeda657b7133d7f96ce4edc8607e51bf902489b65c2 |
| 后端代码/app/services/ai/contract/AiIntentResultContract.php | cc5f3b9ac8481c3f9b35ec9d84b12795d4f6965aa6ec53d1b45a949d5c26e8f2 |
| 后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php | fbdca7c09bc9bcde6bee0c25326461bb3c5d9bcde7e3f868e83603aed31eba20 |
| 后端代码/app/services/ai/skills/intent_understanding/SKILL.md | 5ed50b750b950d2c963fae55b67145f9e6731a0091a956116d73e9c8e55ed993 |
| tests/mohe-ai/gateway-integration.php | 1cb7667053a00da3296c2c7ae851bbea7b2e4680ccdcbb485d44fd17dd5ddb5a |
| tests/mohe-ai/intent-understanding-contract.php | 931e1db720cccdfe8c7925d23305fa00069c26ec7e03c462b2cf64dd9826fb1b |
| tests/mohe-ai/r59-local-synthetic-meaning-probe.php | 38c9660f28d378ea319f9d8580f47d1fbc6ad48c03c78196df510fff983cbb60 |

当前不是“开发与自测全部完成”：四选项推荐、无关句子的完整链路分类以及超过25秒样本仍未通过。没有 commit、部署、push 或小程序上传。


## 2026-10-02 同任务语义补充与真实页面追问复测（仍有未通过项）

本节替代前面的实现说明与候选指纹，不改写历史失败。基线 HEAD 仍为 `a242ea3c130b5fe89f5ebf4c80874bc24b0e95df`，仅本地开发；未暂存、未 commit、未部署瑞昊、未 push、未上传小程序。

### 已落地的职责与代码清理

- 完全无法识别业务含义时，接受空理解后直接返回日常查询引导，不再调用绑定模型补造指标。模型对空理解附加的通用推荐会被丢弃，不把装饰字段误报为技术故障。
- 部分理解的语义推荐与可执行指标分离：正常语义卡展示模型的三个非执行推荐，第四项由两端统一提供补充描述。删除曾经用登记词精确命中筛选自然语言推荐的分支；不再默认为门店或按目录顺序凑选项。
- 补充和推荐选择继续同一个 Run，通过原来的 revision、submission/idempotency 和权限边界。原始脱敏问题、已接受的结构化含义和私有引用映射只在加密服务端状态中保存；原问的明确对象、时间等槽位保留，当前明确修改优先，独立新话题清除旧上下文。
- 同 Run 的新理解阶段使用单独的 attempt 后缀，不重置计数和预算，也不再因请求内容不同产生 attempt 冲突。清晰日期在部分理解阶段也先按日历规范化，防止补充时重新落成今天或包含未来日期的整月。
- 两端已删除 `AI_INTENT_PARTIAL` 的“取消再拼接重提”专用交互及 `supplementAndResubmit`、`retryPartialQuestion` 旧实现。当前补充直接调用同 Run 的 clarify 接口；历史失败记录仍可读取。
- 会员详情可接续完整的会员分组列表：从签名 Reader 视图及现有完整展示投影获取同一批身份，不从20条预览猜名单，不按名称重新搜索；保留零值隐藏和跨指标合并规则。损坏、截断、身份不一致或将分组序号冒充排名仍拒绝。
- “查看已有名单”作为已接受的呈现续问处理：只允许同话题、相同对象、无新增指标/期间/筛选的结构化请求，回放原已验证视图。旧的 `verified_ranking_detail/executeVerifiedRankingDetail` 专用入口已合并为 `verified_result_presentation/executeVerifiedResultPresentation`，源码与永久测试引用扫描无旧入口，历史证据不删除。
- 新增/修改注释覆盖网关语义保存与合并、理解与推荐契约、私有引用稳定性、attempt 预算、两端补充请求、会员结果集与通用回放边界。没有改收银页面、生产配置、数据库或统计口径。
- 仅扩充推荐提示词与目录提示的实验没有实测收益，已撤掉相关生产代码与试验测试；没有为失败的推荐保留一套额外业务路径。

### 真实电脑页面结果

环境：`http://127.0.0.1:18081/admin/setting/mohe-ai`，真实本地接口与已授权模型，实际操作工作台，不是 DOM/HTTP 夹具。

| 问题或操作 | 实际结果 | 页面耗时 | 判定 |
|---|---|---:|---|
| 蓝色的咕噜把月亮折起来 | 返回“我还没理解您想查什么……今天的现金业绩、消耗业绩……”；无取数 | 8秒 | 该样本通过 |
| 这个月会员那个金额的情况 | 语义卡有三个自然语言推荐＋第四项补充 | 一次服务端到卡片约15.3秒 | 数量和显示通过；推荐可执行性尚未通过 |
| 第四项补充：按现金业绩，列出每个会员 | 同一个问题/Run继续，返回本月会员现金业绩列表，期间保持2026-10-01至2026-10-02 | 20秒 | 该样本通过 |
| 同会话：明细呢 | 修复前无法定位会员；修复后返回同一批38位会员当前权益，预览20条，出现Excel下载入口 | 修复前9秒；修复后12秒 | 复测通过 |
| 同会话：详情呢 | 同一批38位会员当前权益，预览20条 | 修复前10秒；修复后7秒 | 复测通过 |
| 同会话：展开看看 | 同一批38位会员当前权益，预览20条 | 修复前11秒；修复后7秒 | 复测通过 |
| 同会话：具体名单给我 | 修复前误进无条件的condition_list；修复后回放原会员现金业绩名单、同一期间与结果 | 修复前17/12/17秒；修复后9秒 | 复测通过 |
| 同会话：这些会员的资料 | 同一批38位会员当前权益，预览20条 | 修复前9秒；修复后12秒 | 复测通过 |
| 第一推荐：这个月会员的充值本金是多少？ | 绑定模型传输超时，尚未取数；诊断为bind_intent_reply1、errno28、elapsed_ms30010 | 页面累计51秒 | 未通过：响应时间及推荐续查 |
| 第一推荐重试 | 返回“当前暂不支持这种分析方式，请调整问题。” | 20秒 | 未通过：推荐与执行形态不匹配 |

数据随其他业务任务可能变化，38位是本次签名结果集，不将它写成未来永久期望值。五种追问在同一个会话接续该结果；“名单”回放原经营数据，“资料/详情”读取该批会员当前权益，不混淆两类结果。没有声称全部金额已经单独SQL对账或实体手机已经通过。

早期电脑热更新仍加载旧的共享前端模块，导致一次补充“取消再新建 Run”；重启本地 admin 编译并刷新后，才验证到真正的同 Run 请求。旧包结果不计入最新版本验收。另一次重启服务期间的执行被中断，已取消，不将其98秒视为模型正常查询耗时。待确认卡片的主动取消显示过数分钟，其包含人工等待，也不作为取数性能样本。51秒未通过样本的模型绑定阶段本身确实等待约30秒，不能用排除人工等待来掩盖。

### 自动化与构建验证

- 理解契约241、网关集成171、网关组件141、语义引导104、上下文67/99、管理网关36、语义投影21 checks PASS。
- 会员详情39、对象详情78、分组查询63 checks PASS；包括完整结果而非20条预览、零值与负数、截断/损坏/错对象、新增期间/指标/筛选以及新话题不得走呈现回放。
- 电脑DOM/HTTP契约180、小程序真实控制器降级夹具193、工作流34、传输10 checks PASS。状态/attempt76、状态引导92 checks PASS。
- HBuilderX5.26本地mp-weixin编译成功，20页、约7.8秒；已有span选择器警告未由本次产生。编译产物为生成物，不是上传或微信真人验收。
- PHP语法与git diff --check通过。完整run-all仍停在冻结R5 holdout的13条既有不匹配（27/40匹配），未把单项回归写成全量绿灯。

### 当前未完成边界

1. 三个推荐“显示出来”已经通过，但选择后“能正确回答”没有全部通过：当前推荐会把一批会员引向不支持的summary形态，且存在绑定模型30秒超时。不能把增加提示词或重新测试一次当成解决。
2. 还没有证明所有真实样本均在25秒以内。前述51秒失败保留；旧记录26/72秒也不改写为通过。
3. 旧内部无模型推荐的兼容澄清载体仍可能只有补充入口，不声称所有未知输入都保证三个可信推荐。没有用目录顺序或固定指标填满卡片。
4. 小程序仍连接瑞昊线上，本轮后端没有部署。已完成控制器夹具及本地编译，但新语义链路的线上小程序/实体手机验收尚未进行，不能写“两端真人验收通过”。
5. 首个经营列表曾独立导出失败；随后权益详情出现下载入口。该现象单独保留，没有宣称导出稳定性已经由R59修好。

因此当前状态是“已实现核心流程、部分真实自测通过，仍有推荐续查未通过项”，不是“开发与自测全部完成”，也不是待直接上线版本。仍须修正/验收未通过范围，再固定版本交产品经理确认。

### 2026-10-02 阶段提交决定

产品经理先回复“测试通过”，在获知上述两项真实自测失败仍存在、尚未提交上线后，明确要求“先commit，再把两项列出来”。按这次具体指令保存当前阶段成果；本次提交不表示推荐续查与耗时问题通过，不部署或push，不改写历史失败记录。后续修复仍须完成受影响范围自测与验收。提交范围为下列21个tracked文件及本证据记录；显式本地合成诊断脚本和其他任务改动不纳入。

### 本次候选文件清单与指纹

- 后端源码9个、前端源码2个、永久自动化测试10个，下表共21个tracked文件。
- 数据库迁移、工程脚本：无。另有此前单列的本地显式合成诊断脚本，不加入无人值守全量测试；本记录为验收证据，均不当作业务源码。
- 其他任务的工作区改动保留，未纳入闭环暂存或部署。
- scoped tracked补丁SHA-256：`249086b0775282ba67f83e05f1def22848d62271e7f479d07bf5fa1851e330a7`。

| 文件 | SHA-256 |
|---|---|
| `后端代码/app/services/ai/AiGatewayServices.php` | `d98392e4c8ea0e23df260ac55207f664c837747e67b77f4a454a1440f70b3b9a` |
| `后端代码/app/services/ai/contract/AiIntentResultContract.php` | `915586d16251ddd55224bc78c093a42e1658d958f90a42e480b0f387623b53ba` |
| `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `89256b7987ccd11f3f43ff7d4cfdf5979a27c1c18b207cb4df99c92c6fd5ccc4` |
| `后端代码/app/services/ai/execution/AiRunStore.php` | `b66e58892a8b14c97762be42ba53f286d18899b620af045919aac3bbb9c1bbea` |
| `后端代码/app/services/ai/execution/AiWorkflowPlanner.php` | `4904dbca177be67b9eedb5977b69eaba6eef499b7d6077e651cdcfdfcf996d21` |
| `后端代码/app/services/ai/model/AiSafeQuestionProjector.php` | `8ae2db9cee6844f4d38d84803bd2b3e90ecc75b469c5ce8673675eb128b90c13` |
| `后端代码/app/services/ai/model/SiliconFlowClient.php` | `00ff8fef0633b35a028557191614524a96428d281433b1861ab62c62d0893900` |
| `后端代码/app/services/ai/skills/intent_understanding/SKILL.md` | `5ed50b750b950d2c963fae55b67145f9e6731a0091a956116d73e9c8e55ed993` |
| `后端代码/app/services/ai/context/MemberDetailContinuationResolver.php` | `916129c4bad7122f16c5e531e2d6ac439fd3269d524341eb0578b20e0813cb2e` |
| `前端代码/shared/mohe-ai/browser-entry.mjs` | `523ab93f10490505b355e35dae159ab894036c8593ed3f986517be078fd75a04` |
| `前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue` | `b3ef5a250114d7ed0349d5b1f419302550318251854954530337d1167123814b` |
| `tests/mohe-ai/gateway-components.php` | `e77ccf743ae0ed3d4680292fbfb88200c6bba5aadeced658aa418548f86b39b5` |
| `tests/mohe-ai/gateway-integration.php` | `1f4332ea7e558fbc593e1151d6833df762161632d12bb37e9d58a733e49084bf` |
| `tests/mohe-ai/intent-understanding-contract.php` | `2c67ba34ce7f3e91a2a2a3a5a501fd33f1450f5edffa3f6c8c4e324b3ac2f9d6` |
| `tests/mohe-ai/browser-guidance-contract.mjs` | `748b07dcae03f34d1c44e0883541d50f9df00902ed6ebde5e25072b32058d985` |
| `tests/mohe-ai/browser-mobile-guidance-contract.mjs` | `e560d66bbdddb6fd0661c87076467bc6f4af5b4c27bd4061840751a0d552c5aa` |
| `tests/mohe-ai/management-gateway.php` | `2f763d5acdebde178fc8efac8a09ba53b2fe42b9dcd9eef908f4586e31e5de3f` |
| `tests/mohe-ai/context-delta-gateway.php` | `fb604c334a46c5999eb1e0cdeb20ad21c8c151e88f7b5213b0fd666052d386dc` |
| `tests/mohe-ai/skill-semantic-projection.php` | `237e92e49a9191ababd55858020ec602b76cca26f02b2648dd3b9702334306bc` |
| `tests/mohe-ai/member-detail-continuation.php` | `1d70ac7bdbb5ac06837be9e7b3e4b2d3511bb95e4a2d9ddb7f474e10bcbb76c0` |
| `tests/mohe-ai/object-detail-continuation.php` | `48105e98bc3c8177a7562b5f56d465b405339c3551de95e16b4fea6f2b240d8c` |

## 2026-10-04 明确排名条件的推荐候选修复

基线 HEAD：`ac2855225a864ea2b512674d827984d62d68348d`。本节只记录本次对未提交 R59 推荐路径的补修；不覆盖前述历史测试结论。

- 故障：理解结果含“前 5 名”但未另给 `operation` 时，候选会同时出现逐个明细和合计，点击这些选项将丢失名次约束。修复后明确 `ranking` 优先决定回答形态；若当前同时显式要求非排名形态，候选为空，不能伪称安全执行。
- 回归中另发现：从已签名的逐店明细追问最低 5 名，原明细的 `object_kind=store` 展示载体原样进入排行，登记编译器拒绝。现在仅在原门店维度无对象选择的两种合法载体之间按目标形态转换；已签名 `store_ids`、日期、其他私有筛选与条件不被清除或放宽。反向从排行追问逐店明细也验证可执行。
- 数据架构自检：权威事实源、事实粒度、业务生效时间、事务/并发/幂等/Outbox、冲销、维度快照、数据权限、索引及历史覆盖均未修改；候选仍以当前账号能力快照和登记编译器做无取数预检，真正查询仍走原统一指标 Reader 与权限重检。本次无 SQL、迁移或统计口径变化；对账方式为编译后逐字段核对查询形态、名次、日期和门店范围。
- 永久回归：`recommendation-planner.php` 109 项（真实登记/编译、无事实查询）、`gateway-integration.php` 180 项、`context-delta-gateway.php` 102 项、`management-gateway.php` 36 项、`intent-understanding-contract.php` 241 项通过；PHP 语法与 `git diff --check` 通过。未将合成/本地编译测试写成线上或实体手机真人验收，也未声称所有问答均低于 25 秒。
- 注释同步更新：`AiSemanticRecommendationPlanner.php` 的明确名次与门店维度载体分支、`recommendation-planner.php` 的场景边界。新增逻辑复用既有候选路径，未引入第二套实现；未发现可独立删除的旧业务分支。前端源码、数据库迁移、工程脚本和生成物均无本节变更。
- 文件 SHA-256：`AiSemanticRecommendationPlanner.php` `33da37c976521dd4bfc146fa9b0ec36fa1d5f284c8e2859db8c1f977fb6496d1`；`recommendation-planner.php` `3f9dbd2ef62d4cb7959d5c3e4a42ee9325da3e7a5236655388fa0fad43ba7ce9`。其他 R59 工作区改动及不同任务脏文件保持原状。本节尚未取得产品经理对固定版本的验收；没有 commit、部署或 push。
