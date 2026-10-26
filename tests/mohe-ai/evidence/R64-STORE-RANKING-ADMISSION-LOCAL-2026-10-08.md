# R64 门店高低追问与请求接收恢复：本地自测、待产品经理验收

- 基线 HEAD：`4c5cf1c39495e8d330aff6df870a216b2958ff14`。本记录扩展此前 R64 纯时间追问的未提交固定版本；此前证据保持历史原样，本记录中的文件指纹为新的待验收版本。
- 用户复现：同一对话“前天业绩如何 → 10月2号呢 → 这个月业绩最高是哪天 → 哪个门店业绩最高哪个门店最低”，最后一问桌面端超过 100 秒仍显示“正在思考”。
- 本地只读诊断：首次浏览器 `POST /runs` 未获确认，页面未自动恢复，原请求未形成 Run；手动重试后模型与绑定约 10 秒内结束，却把“比较授权门店”误作“选择一个门店”，并把已验证的“实际业绩”退回指标选择。两种问题分别处理，不把页面等待冒充数据查询耗时。

## 修复边界

1. 浏览器创建 Run 的请求仅在 12 秒无响应时中止传输，最多自动重试两次，始终复用原 `client_request_id` 和完整请求体；连续未知后停止可见“正在思考”计时，保留同一任务的手动确认入口。已收到 Run 的轮询、取消、导出不套用该超时。
2. 语义合同保持“分析门店”与“选定一家门店”的区别：已验证前问没有门店限制、当前也没有命名门店时，不允许模型仅因对象是 `store` 就替换门店范围。
3. 前问已有签名验证的单一指标、候选指标与之相同时，独立复核读取上文而非只看孤立的当前泛称；明确改问别的指标时仍由复核和指标契约决定。最高／最低共用一个指标和对象，不按词语穷举。
4. 理解阶段要求同句高低比较的证据引用完整原句，避免拼造不在原话里的证据片段。没有新增客户专属规则、指标或 SQL。
- 本次补充／更新约束注释：`browser-entry.mjs`、`browser-transport.mjs`、`AiIntentResultContract.php`、`AiIntentUnderstandingContract.php`、`SiliconFlowClient.php`；已核对相关旧路径，仍在调用的幂等重放及候选复核不能删除，本次无完整替代的废弃分支。

## 固定文件 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/shared/mohe-ai/browser-entry.mjs` | `d4ba0a8ae4f28c5f89ba9e79c48d8f3e5d044a0494083c5b4c414cfe358bb212` |
| `前端代码/shared/mohe-ai/browser-transport.mjs` | `4edc491c29d4167f024f847f0e345eb02d6daa93c513ec3de74130606d13044d` |
| `后端代码/app/services/ai/AiGatewayServices.php` | `ecfccea6324a139d790d3adcfc35223762c74f8438d50ae56716da2db079614c` |
| `后端代码/app/services/ai/contract/AiIntentResultContract.php` | `815914506ef8ca8296a71194ae01e53b76bd45ab53c2cfe2a86fe704a399a0c1` |
| `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `7b26792a0c9bb03cd3f353cf57e8c1e31f1fce5e1ae06c6b1acaeb62790e710e` |
| `后端代码/app/services/ai/model/SiliconFlowClient.php` | `70d12f8efb0b948e719ccafddfbc5e04ef9761da866bd39cb0ff4047bf2fa74f` |
| `后端代码/app/services/ai/semantic/AiSemanticIntentParser.php` | `da1a528238c6bbb4b98727366e9128959c06bd0c871ed1db973f2cde604148de` |
| `tests/mohe-ai/browser-workflow-contract.mjs` | `72abfbfed572e713a8ffe5792a0e66160123fca7b8852d3951bdd4000d9640a5` |
| `tests/mohe-ai/browser-transport-contract.mjs` | `1af45e89ab80286396fca158ff10437d33a259b2cc9ab6a2fc2b5a0b9ad983a0` |
| `tests/mohe-ai/intent-understanding-contract.php` | `b13b1b62c4a25b3f7cb00e5a31f0f017397be147901585cdbde31303e87be160` |
| `tests/mohe-ai/r64-model-calendar-followup.php` | `5bf9c4be5f7094dc44d182fd77ef03e468a0ab4f28c17bc9a14b3d1ca671ecdf` |
| `tests/mohe-ai/r64-week-followup-local.php` | `49d1e779c4ec1a7f7ce54e618fe27ce4ccf276399e24363a7ab00240605c16b6` |
| `tests/mohe-ai/r64-store-ranking-followup-local.php` | `66ea5ce315f15bc2aff9566ceb3996f2abcf07be1cc4b10fab96553dfc528636` |

上述已跟踪文件的 Git 补丁 SHA-256：`b06d56e483296f0e7d711b19b2c7761dff8bbce1ee91beed6c613f1b92b05881`。证据文件本身不属于源码指纹。

## 测试记录

- 浏览器 DOM/HTTP 夹具：`browser-workflow-contract.mjs` 42 项通过，含首次接收未知自动以同一请求编号重放、连续三次传输未知后停止思考计时；`browser-transport-contract.mjs` 11 项通过，确认只有 Run 创建附加可中止的截止时间。`browser-entry-contract.mjs` 通过。
- PHP 合同与回归：`intent-understanding-contract.php` 263 项通过，新增“分析门店不变成选定单店”；`semantic-binding-guard.php` 12 项、`gateway-review-regressions.php` 85 项、`gateway-components.php` 144 项、`context-delta-gateway.php` 102 项、`query-context.php` 67 项通过。相关 PHP/JS 语法检查及 `git diff --check` 通过。
- 本地 `ruihao` 实例真实模型、授权 Reader，脚本 `r64-store-ranking-followup-local.php`：在同一会话先问“这个月按实际业绩看，哪天最高”，再问“哪个门店业绩最高哪个门店最低”。两次独立复测中，后问均 `COMPLETED`，分别耗时 11,751 ms（Run `6844e2367ca9fb1a2bd59ecb3a76340cd8836c08e07675c7`）和 10,828 ms（Run `e7b7cc6cd121dadf6d610110aa30e84da4cfe01de6cf84b2`）。查询证据为 `actual_performance`、本月日期、授权门店的 `top_and_bottom` 排名，未选定单店。
- 最终三轮同会话复测：第 1 问 9,704 ms（Run `f6fb7065fff3231df3d4f9313c4f818fcf2b56774835e8a7`）；第 2 问 11,236 ms（Run `66042b66f5732bb89ed1f4963bf80e72e3dc0184b7fe5f8a`），继承实际业绩；第 3 问“那按现金业绩看，哪个门店最高最低”8,857 ms（Run `bd4c3f753711ae22f255632d4407ee56e3b4dcdbca2281c8`），正确切换到 `cash_performance`。测试输出不含门店名称或金额。
- 真实模型曾有一次输出非原文证据导致 `evidence_not_unique`，为此收紧同句高低比较的证据指令，之后上述重复实测通过；模型响应仍可能波动，不能宣称 100% 成功。执行进程曾因旧契约指纹报 `AI_CAPABILITY_CHANGED`，确认无执行中 Run 后仅重启本地 AI worker；正式部署必须使 HTTP 与执行进程加载同一版本。

## 交付与授权状态

- 前端源码：上述 2 个浏览器文件。后端源码：上述 5 个 AI 文件（含此前 R64 改动）。数据库迁移：无。自动化测试：上述 6 个测试文件。工程脚本：无。生成物：无。验收证据：本文件及此前 R64 记录。
- 开发及本地自测完成，待产品经理对这一固定版本确认验收；未 commit、未部署瑞昊、未进行线上双端验证、未 push。现有大量其他任务的脏文件保持原状。本地重启不代表线上部署。
