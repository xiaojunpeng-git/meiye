# R55 上下文继承与话题边界：本地自测交接

状态：开发、自动化回归和本地电脑端真实问答自测完成；产品经理已对下述固定版本回复“我确认可以”。准备本地 commit；未部署瑞昊、未 push。手机小程序尚未在此固定版本上做真人验收，本记录不冒充线上或双端验收。

## 问题、修复与业务边界

- 三轮门店问答后，“这两家的退款业绩”被模型当成未选门店，超过 25 秒才要求选择。原因有二：本地旧 worker 未加载新代码；排行转为分组结果后，下一轮模型也看不到服务端签名的完整门店集合。现对模型只披露已验证集合的类型与数量，不披露名称/ID；封闭的多门店指标追问由服务端取回原集合并走统一 Reader、当前权限重验，再次追问仍保留完整集合。复杂或带额外条件的问法继续走模型，不删减条件。
- 独立问“这个月项目销售额第一名是谁”虽答出项目，修复前底层查询仍带上一轮两家门店的 `store_ids`，耗时约 30 秒。这是错误继承。现复用已有注册指标、对象、日期和残余词的严格校验；只有当前问句自己说完整时间和排行、且没有“这两家”等前文指代时，才按新话题编译并清除旧筛选。缺日期、含指代或有无法解释的条件仍交给上下文理解。通用“第一名/倒数第一名”语法不绑定具体指标或客户。
- 切换到项目话题后再说“这两家的退款业绩”，上一条已验证回答不是门店集合，现快速提示补充门店或重查门店排行，不回翻更早的门店 ID。签名上下文与授权 Reader 是唯一可执行来源；模型、浏览器历史和旧回答文案不能自行提供身份。
- 模型理解新增 `topic_relation` 契约；服务端确认新话题时截断电脑端与小程序的旧上下文，即使新话题这一轮失败也不回找旧话题的成功结果。本人同会话但已过期的签名引用不恢复旧数据；独立完整问题可继续。伪造或异主引用仍拒绝。
- 已完成对旧 `IntentContextMerger::rankingConstraints` 的全路径引用扫描并删除；旧的平行继承分支不保留。没有改动指标口径、事实层、权限、数据库或用户经营数据。

## 固定版本

基线 HEAD：`c71bfa7ce8c92b5fc9dbabeb5f573c5eaf60335d`。闭环 18 个源码/测试文件的 `git diff` SHA-256：`9e30faa1ac681a82a5885e880d4a5ed0068d7720e6a36730462ad4b46bea0e6d`。

| 文件 | SHA-256 |
| --- | --- |
| `tests/mohe-ai/context-delta-gateway.php` | `4b9af0d940b8056a8526da014dc49f53975b7ae798f4acb4df36f3d7744bf890` |
| `tests/mohe-ai/device-session-contract.mjs` | `61e5d6b67a94db25325bb736149020bfbc120008d85347e9ab048f594e74bdf4` |
| `tests/mohe-ai/exact-ranking-collection-admission.php` | `1d3b77bc18662857f8dd066de90b7c19186cd18f9b40731e3f30b898584915ab` |
| `tests/mohe-ai/intent-understanding-contract.php` | `9e21873c8894e194d1334a644eff926e3b0e6448d503e45966fd0ec70501dc09` |
| `tests/mohe-ai/mobile-ai-entry-transport-contract.php` | `c80b880893f364ec312afe76bdea577cd0e88a93f7787e5206630d3c9442a2e5` |
| `tests/mohe-ai/object-detail-continuation.php` | `72614a7b951b4e1aedae60cc068f483a3024dc8fee69aaf7a1b0cb639cdc7354` |
| `tests/mohe-ai/r49-ranking-context-local.php` | `baf7503eaf589e617146ccae4517da2ccfa95a3fc88db2763eef2c2dbfbd1791` |
| `前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue` | `6c8d9b78877ba56d1837b9a94e55f183a7ca0c3f29be3bde64d53852bd21a0a3` |
| `前端代码/shared/mohe-ai/browser-entry.mjs` | `40df2dd896d18a687dfccbafe61f57abcb98dbb7889d5a4ffe86139b57dc6fec` |
| `前端代码/shared/mohe-ai/device-session.mjs` | `a1a69e01fadd08c2128fdf98cac37fd620daabefb4ac54d9cdc738272f86f66a` |
| `后端代码/app/services/ai/AiGatewayServices.php` | `5f6b992df0024e6d6508c092939552ac08c0ae5b00e560a14aa8ad39fcdcf560` |
| `后端代码/app/services/ai/context/IntentContextMerger.php` | `57be82c39e1ba294d37ba0a2c88b6ba074df01c2c8bb8a7f9bd0782c8836673c` |
| `后端代码/app/services/ai/context/ObjectDetailContinuationResolver.php` | `75fb05aa064756590d54b96d0f8a6e59a4ebcadbd0bbd5328106d9c6331c6a30` |
| `后端代码/app/services/ai/context/VerifiedQueryContext.php` | `ce3c4e0d31d3c9eee2e99150778ba032ba56a591c5ef795cf641bc6f89b19519` |
| `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `cea2997d1bafb36f3d3bcc59fd2c81aef6c2344061d627b194a5f5a6a4e5ef4c` |
| `后端代码/app/services/ai/execution/AiRunStore.php` | `9a499dac9c5e22b7d45d67cfde9a303d2246831cadc9ec00de9a7d9f49ef8eec` |
| `后端代码/app/services/ai/model/SiliconFlowClient.php` | `0df6dcf3e1296ff7f07eabb384bb70a1e40eacb5efb5ce90dda1c650640d3fc6` |
| `后端代码/app/services/ai/semantic/AiExactRankingCollectionAdmission.php` | `94a9fff4f932fa1163121d805e73eaf235dd3f4db86c94c33ae13f7084a18c90` |

## 自测与结果

- 本地 Chrome 同会话真实数据：新问“这个月现金业绩前两名门店”约 1–2 秒，返回两家已验证门店；“这两家上个月的消耗业绩分别是多少”约 2 秒，完整保留两家；“这两家的退款业绩分别是多少”约 2 秒，完整保留两家。两次分组回答均走签名集合和普通 Reader。
- 另起本地真实会话：门店排行约 2 秒 → “这个月项目销售额第一名是谁”约 1 秒；服务端查询证据显示 `object_kind=project`、`store_ids=[]`、当月日期范围，并有 `topic_boundary=reset`。随后“这两家的退款业绩分别是多少”约 2 秒，明确提示上一条不是门店列表，未借用更早两家门店。修复前项目问答约 30 秒且错误继承 `store_ids`，无效指代约 30 秒才进入选择。
- PHP：`context-delta-gateway.php` 99 项、`object-detail-continuation.php` 71 项、`intent-understanding-contract.php` 228 项、`exact-ranking-collection-admission.php` 45 项、`mobile-ai-entry-transport-contract.php` 40 项、`gateway-review-regressions.php` 85 项、`gateway-integration.php` 155 项，均通过。新增契约覆盖签名集合连续追问、错误话题重置、封闭排行快路径、无效指代快速失败、模型输入不得夹带门店 ID。
- Node：`device-session-contract.mjs`、`browser-entry-contract.mjs`、`browser-transport-contract.mjs`（10 项）、`browser-mobile-guidance-contract.mjs`（178 项）、`browser-workflow-contract.mjs`（34 项）均通过。
- 改动后 PHP lint 与 `git diff --check` 通过。本地 worker 在代码变更后重启以加载当前源码；旧进程下的样本不计入修复后验收。

## 待产品经理验收与限制

- 当前真实问答仅本地电脑端。小程序连接瑞昊线上，线上尚未部署此固定版本，因此小程序真人与两端同结果验收待部署授权后完成。现有移动端静态/控制器合同通过，不能代替实体或模拟器业务验收。
- 上述 1–2 秒是本次封闭问法的观察值，不代表任意开放自然语言问题都低于 25 秒。缺省日期或复杂条件仍交由模型理解，性能继续按线上实测记录。
- `r49-ranking-context-local.php` 需 `MOHE_R49_LOCAL_TEST=LOCAL_READ_ONLY` 与本地 MySQL 事实数据，本次未运行。工作区还存在其他任务脏文件，均未纳入本闭环、未暂存或提交。

## 文件分组、注释与清理

- 前端源码：`browser-entry.mjs`、`device-session.mjs`、`mohe-ai-entry.uvue`。
- 后端源码：`AiGatewayServices.php`、`IntentContextMerger.php`、`ObjectDetailContinuationResolver.php`、`VerifiedQueryContext.php`、`AiIntentUnderstandingContract.php`、`AiRunStore.php`、`SiliconFlowClient.php`、`AiExactRankingCollectionAdmission.php`。
- 自动化测试：本记录表内 7 个 PHP/JS 测试文件。验收证据：本文件。数据库迁移、工程脚本、生成物：无。
- 在网关、语义契约、签名上下文、排行语法、模型输入、运行状态与双端会话修改处同步添加/更新业务边界注释；全路径扫描后删除已失效的排行继承代码，无业务口径平行实现。
