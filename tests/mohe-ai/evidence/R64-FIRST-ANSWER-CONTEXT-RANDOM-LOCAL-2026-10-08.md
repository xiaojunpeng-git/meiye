# R64 首答、上下文与引导卡：本地无规则真人测试（2026-10-08）

## 范围与版本

- 源码正本：`美容源码/`；本地平台页面：`http://127.0.0.1:18081/admin/setting/mohe-ai`；后端为本地 `ruihao` 实例，未连接瑞昊线上。起始 HEAD 为 `572268e9196574ebaad24ecd95a6fe920edc7161`，下列修复仍在未提交工作区，**尚未形成产品经理验收的固定版本**。
- 电脑端在独立的本地浏览器测试标签中，使用已登录管理账号真实输入、发送并查看回答。各链内部保持同一会话；需要隔离首答时新建会话。不同链无规则穿插选用 B05/B08、C08、E01/E09、F07/F08、G03；没有按用例编号顺序执行。
- 只记录本地页面看到的金额和后台已验证查询口径；本次未独立重算财务事实金额，**不将页面可见等同于金额对账通过**。用时为页面显示值，不含人工停顿；未采集每个 UI Run 的独立分段时延。

初版记录的核心文件补丁指纹：`git diff --binary` SHA-256 为 `1c942bed0c280e46309e545106c3a6996cd5ca14b595a4153120f9ff957c5b6f`；本地回归脚本 SHA-256 为 `1c378fe6e3eb8c923ef26ae837ee4274be04311f90451ec0c107dc8b29c0a8b5`。下表是初版核心文件逐文件 SHA-256（不包括本段所在的自引用证据文件）；最终提交清单还包含下方注明的本轮依赖文件，以提交树为完整固定版本：

| 归类 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端 | `app/services/ai/AiGatewayServices.php` | `712faed2221c03ec4f541e269c75d31779b694558d9c2b30660829691bfd7347` |
| 后端 | `app/services/ai/presentation/AiAnswerRenderer.php` | `a6c78976036ab42b0c8897e692809e2565e7dfa9221600bce5a5afd0d0a40d33` |
| 后端 | `app/services/ai/semantic/AiExactRankingCollectionAdmission.php` | `dc8d9903a56b556ccc4aecab38b58a6cc28962b815eeeb59575464b3f7a21662` |
| 后端 | `app/services/ai/execution/AiSemanticRecommendationPlanner.php` | `5436c48a629a8ef6a0eebde325d1d501b032a1d7cef76806e13c1ac4c0a5e0f6` |
| 后端 | `app/services/ai/execution/AiDimensionGuidancePlanner.php` | `23dbe7d4af1ba9f7585f8579048a2d6e9654b6e1a1b71a388160a6baa5af7f89` |
| 后端 | `app/services/ai/execution/AiWorkflowPlanner.php` | `f2f883bd5b8b192acbe08c0c0eabc7e9d66a213cb8f97be95b788bbfe4ce4687` |
| 前端 | `shared/mohe-ai/browser-entry.mjs` | `c5a03d14fbc51d3695a8a84ccc242d56c68ea64f35d4d103ac14dbb24a0c1a47` |
| 前端 | `mobile-vue3/src/shared/components/mohe-ai-entry.uvue` | `c215280eb156be6a7482d2895a2b62428ef4fd44727c877e80a2a9623c84e972` |
| 自动化 | `temporal-ranking.php`、`r27-ranking-presentation.php` | `e4ae9ea7a7fc288e8849062d0b10c343e568d0acb7da240d8f152b9f91dba1ba`、`d2a7f7b9fae6d3aa604c393c65a6db541f0abaaa3284a2dd72841f39c099b34b` |
| 自动化 | `gateway-components.php`、`intent-understanding-contract.php` | `835cd9c3be295561d337ff8eaf87613b543e3550208a42700d95c7cb530f72f4`、`b13b1b62c4a25b3f7cb00e5a31f0f017397be147901585cdbde31303e87be160` |
| 自动化 | `browser-guidance-contract.mjs`、`browser-mobile-guidance-contract.mjs`、`browser-workflow-contract.mjs` | `5386f2ed004bfcc1cc8b84491dda1c100cb3ff7d0f030c11d4f2f96750ba7ba6`、`699331271dfc9e088998ad69cf54f9c3ef12ca9479322cb810714b068872ab40`、`72abfbfed572e713a8ffe5792a0e66160123fca7b8852d3951bdd4000d9640a5` |
| 测试用例 | `MOHE-AI-ROUND22-29-TEST-CASES.md` | `0cdd61f70fa4541922c99f467dd865aefe21564859a1a3fe90c99d5c10b4780e` |

提交前补核：本轮首答与上下文修复还依赖 `AiIntentResultContract.php`、`AiIntentUnderstandingContract.php`、`SiliconFlowClient.php`、`AiSemanticIntentParser.php`，请求接收等待修复还依赖 `browser-transport.mjs` 与其合同测试；六者在本地验证时均已生效，初版表格漏列，不能只提交上表文件。其 SHA-256 依次为 `815914506ef8ca8296a71194ae01e53b76bd45ab53c2cfe2a86fe704a399a0c1`、`7b26792a0c9bb03cd3f353cf57e8c1e31f1fce5e1ae06c6b1acaeb62790e710e`、`70d12f8efb0b948e719ccafddfbc5e04ef9761da866bd39cb0ff4047bf2fa74f`、`da1a528238c6bbb4b98727366e9128959c06bd0c871ed1db973f2cde604148de`、`4edc491c29d4167f024f847f0e345eb02d6daa93c513ec3de74130606d13044d`、`1af45e89ab80286396fca158ff10437d33a259b2cc9ab6a2fc2b5a0b9ad983a0`。提交前复跑了相关合同、语法与 R64 模型时间续问离线测试，均通过。

## 修复前真实失败样本（保留）

| 路径 | 真实表现 | 定位 |
| --- | --- | --- |
| 今天现金 → 上周 → 本月最高日期 → 哪个门店最高/最低 | 后一问 14 秒，已验证 Reader 同时有 top/bottom 各 20 行，页面只写最低、前 20 行全是最低方向；Run `45b5f380f3b16bed6bc208ca8ec69fac991d7c5247a3f60d` | 排名结果持久化后方向顺序是 `bottom, top`，渲染器照输入顺序组装并截前 20 行；不是 Reader 未查最高。 |
| 今天消耗业绩 → 上周呢 → 今天天气如何 → 上周呢 | 天气给了能力说明，但随后的“上周呢”仍在 6 秒内报消耗业绩 555,249 元 | 无关话题的失败回答未设置 `topic_boundary=reset`，客户端继续引用旧签名结果。 |
| 修好边界后的中间复测 | 旧消耗指标不再继承，但无业务前文的“上周呢”在 9 秒内被模型默认成实际业绩 705,011 元；Run `e09db58d234357849af00a7f3c6a59ee2dc6b21f5052250e` | 只有时间、没有业务对象/指标的短句不应让模型凭默认指标取数。 |

## 修复后本地电脑端实操

| 会话内顺序 | 问句/变体 | 页面用时与实际结果 | 核对点 |
| --- | --- | --- | --- |
| A1 | 今天天气如何 | 3 秒，简要说明可查的门店经营数据，无卡 | 无关问题不误弹问答卡。 |
| A2 | 今天现金业绩是多少 | 1 秒，现金业绩 11,038 元；10-08 单日 | 无关话题后完整业务首答独立成立。 |
| A3 | 上周呢 | 4 秒，现金业绩 705,011 元；09-28 至 10-04 | 纯时间追问继承已验证现金指标和自然周。 |
| A4 | 上周营业额最高是周几，最低是周几 | 7 秒，现金指标、09-28 至 10-04；页面列出最高周五 10-02 和最低并列周一/周二 | 日期双向排行、周几与精确日期、并列值。0 元日期由当前日报日序列补零；金额未独立对账。 |
| A5 | 这个月业绩最高是哪天 | 2 秒，仍为现金指标、10-01 至 10-08；最高周五 10-02 | 完整泛称追问没有覆盖已验证指标，也不弹卡。 |
| A6（修后重问） | 哪个门店业绩最高哪个门店最低 | 10 秒，现金指标、同月；正文同时给出最高霍山文峰店 254,268 元和最低邵武五四店 0 元；20 行预览交错含两端，完整 40 行有 Excel 下载 | 原失败修复；仍有总共 40 行是模型本轮选择的排名数量，不是把 20 行冒充两端完整排行。 |
| A7 | 今天消耗业绩是多少 | 1 秒，消耗业绩 66 元，10-08 | 明确切换指标，不沿用现金。 |
| A8 | 上周呢 | 6 秒，消耗业绩 555,249 元，09-28 至 10-04 | 切换后继承新指标。 |
| B1（新会话） | 上周呢 | 4 秒，能力说明，无卡、无经营数值 | 无可信业务前文时，纯时间短句不补造指标。 |
| B2 | 上个季度营业额总的是多少 | 6 秒，现金业绩，07-01 至 09-30 | 完整业务首答正确换算自然季度。金额未独立事实对账。 |
| B3 | 这个月业绩最高是哪天，最低是哪天 | 1 秒，沿用现金指标；10-01 至 10-08，两端日期与金额均列出 | 日期、对象和方向改变时保留同话题指标。 |
| B4 | 明天天气怎么样 | 3 秒，能力说明，无卡 | 明确切走经营话题。 |
| B5 | 上周呢 | 5 秒，能力说明，无业绩查询 | 切话题后不回收旧签名结果，也不让模型默认实际业绩。 |
| B6 | 这个月哪天表现最好 | 12 秒，实际业绩日期排行 | 作为无前序业务指标的自然改述样本记录；本句没有触发卡，不把它记成 F08 卡片真人验收。 |

首答及继承另经本地真实模型/Reader 随机四链七问通过（脚本 `r64-context-priority-local.php`，固定随机种子 6408）：`前天现金业绩是多少 → 这个月呢，哪天最高`、`昨天收了多少钱 → 这个月业绩最高是哪天`、`这个月业绩最高是哪天`、`这个月按实际业绩看，哪天最高 → 哪个门店业绩最高哪个门店最低`。实际模型链用时约 1.3—10.7 秒；签名查询中的指标、形态与对象符合各链预期。该脚本仅能在显式 `MOHE_R64_CONTEXT_TEST=LOCAL_ONLY` 和校验通过的本地库运行，不能视作生产或小程序真人测试。

## 自动化与未通过项

- PHP：`temporal-ranking.php` 17 项、`r27-ranking-presentation.php` 6 项、`gateway-components.php` 146 项、`intent-understanding-contract.php` 263 项、`r63-failure-guidance.php` 通过；修改的 PHP 文件及本地脚本语法检查通过。
- 前端：`device-session-contract.mjs`、`browser-guidance-contract.mjs` 199 项、`browser-mobile-guidance-contract.mjs` 218 项、`browser-workflow-contract.mjs` 42 项、`browser-entry-contract.mjs` 通过；这些是 DOM/回调合同测试，不代替原生界面。
- 旧 `gateway-integration.php` 已知 Q001 现金 323 元夹具断言仍失败；不是本次修复新增的功能结论，未涂改成全套通过。全量财务对账与全套历史用例本次未执行。
- 本轮修复后实际页面样本中未出现超过 25 秒的问答；历史 R62 线上 26/28 秒仍保留为性能回归项，不能据本轮样本宣称长尾耗时已全部消除。
- 微信开发者工具 `mobile-vue3` 窗口可打开，但模拟器显示 **“模拟器启动失败 / Error: simulator launch failed”**；尝试模拟器重启和重新编译后状态不变。且 R64 后端只在本地未提交工作区，未部署瑞昊；因此**小程序真人问答、双端同版结果均未通过验收**。本轮未上传小程序、未设体验版。

## 代码与交付边界

- 后端：`AiGatewayServices.php`（首答/可信上下文、纯时间边界及能力帮助重置）、`AiAnswerRenderer.php`（双向排行两端同显）、`AiExactRankingCollectionAdmission.php`（日期话语连接词）、`AiSemanticRecommendationPlanner.php`、`AiDimensionGuidancePlanner.php`、`AiWorkflowPlanner.php`（不足三个可执行建议时用真实恢复动作补位，动作不得作为指标提交）。
- 前端：`browser-entry.mjs`、`mohe-ai-entry.uvue`（问答卡三个推荐位置的恢复动作；第四项仍可自行补充）。
- 测试：上述 PHP/JS 合同、`r64-context-priority-local.php` 及本记录、用例索引。没有数据库迁移、工程脚本或生成物交付。
- 关键业务/上下文/模型契约分支注释已随变更补齐；临时只读诊断分支已从本地回归脚本移除。没有删除历史验收证据或其他任务代码。
- **未 commit、未部署、未 push。** 待小程序模拟器恢复、同版复测与产品经理对固定版本验收后，才进入项目既有提交/发布门禁。
