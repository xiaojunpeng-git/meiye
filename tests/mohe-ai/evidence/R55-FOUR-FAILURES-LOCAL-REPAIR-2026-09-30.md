# R55 线上乱序复测失败项：本地修复与固定版本

## 范围和状态

- 基线 HEAD：`886c181f2712fba1c9ad8964e521036947aac115`；补丁 SHA-256：`6ad8cb227bb1517ae57dc90f0b78f2959ccf7b14c64b4d482d0f9a6d3641cf50`。
- 本记录只覆盖上一份 `R55-RH-DEPLOY-RANDOM-RETEST-2026-09-30.md` 中的功能失败 #10、#13、#24、#43，以及慢样本 #35、#57。尚未提交、部署或推送本次补丁；需产品经理对本固定版本确认验收后按项目门禁继续。
- 前端源码：无。后端源码：下列 6 个 PHP 文件。数据库迁移：无。自动化测试：下列 5 个 PHP 文件。工程脚本：无。生成物：无。验收证据：本文件及已有线上复测报告。
- 不修改指标定义、业务事实、权限、金额口径或 Reader SQL；电脑端与小程序仍共用服务端同一套执行规则。仅消除误解、脱敏碰撞、签名查询重放和不必要的模型往返。

## 修复及本地真实工作台验证

本地 `18081` 平台工作台、`admin` 现有登录态，输入并发送真实问句；后端使用当前 `美容源码/后端代码/` 绑定的本地容器。在源码变更后刷新 `mohe-ai-execution-worker` 常驻进程，再测新版本。所有耗时为页面显示时间；不将本地结果冒称瑞昊线上结果。

| 对应线上项 | 原因与边界 | 本地实际操作及结果 |
| --- | --- | --- |
| #10 严格大于金额 | `严格` 恰好碰撞已授权对象的私有名称，脱敏破坏 `严格大于`；比较词语法也未收录。仅保护“比较词后紧跟数值”的公共语义，孤立同名对象继续脱敏。 | 原句先后 28–30 秒失败；修复后原句 **2 秒**返回 49 人、09-01 至 09-30。独立新会话的普通“大于”也 **2 秒**返回 49 人、同一期间；同义表达一致。未逐条独立复算会员金额。 |
| #24 / #57 到店＋实际收款双条件 | 日历扫描把注册指标“到店”误读成残缺的“到…”；模型省略独立期间字段时多做修复往返。只恢复当前问句完整的一个期间，不删除任一条件。 | 原句新会话 **21 秒**完成，保留到店次数 `>=2` 与实际收款销售额 `>=10000元` 的 AND 条件，09-01 至 09-30；返回 9 人。页面结果中两项指标均列出；名单全集尚未独立复算。 |
| #13 已展开排行再指第二位 | Reader 签名查询的空 `condition_set` 被后续编译器当成多余字段；原重试虽正确返回，但模型理解耗 **44 秒**。编译器只忽略空值；仅对完整的“第 N 位详情”短句从已签名、权限重放的排行视图选序位，混合条件仍走模型。 | 同一会话依次问“这个月所有员工业绩前两名”→“详情呢？”→“第二位具体看看”：前两问 14 秒、6 秒，第三问由 **44 秒降至 5 秒**，详情对象与原第 2 位一致，期间未变。 |
| #43 门店集合展开并导出 | 模型在纯详情追问中重复写入旧排行，绑定器误认为新排行要求。仅当重复排行与签名旧查询完全相同时忽略，修改排行仍正常绑定。 | 同一会话问“这个月门店业绩前三的门店详情”→勾选 Execl 问“把这几家展开看看”：首问 **2 秒**、追问 **8 秒**，保持原 3 家和 09-01 至 09-30；页面出现“Excel 已生成”和下载按钮。未逐格下载校对文件内容。 |
| #35 今日劳动业绩首名 | 一个已注册人员指标、明确首名和“谁”的闭合问句仍经历多个模型阶段。新增受注册粒度和完整残句限制的本地确定性排行入场；不按整句写死结果。 | 新会话“今天劳动业绩第一名是谁？” **1 秒**完成；当天无符合记录，按事实空集作答。 |

## 自动回归与源码边界

- `condition-set.php` 119 项、`r50-calendar-range.php` 37 项、`intent-understanding-contract.php` 225 项、`object-detail-continuation.php` 44 项、`registry-execution.php` 105 项、`context-delta-gateway.php` 93 项、`exact-ranking-collection-admission.php` 54 项、`gateway-integration.php` 155 项、`runtime-contract.php` 105 项，均通过。上述夹具/SQLite 测试不替代真实业务金额对账。
- 六个后端文件在本机及本地容器 PHP 语法检查通过；`git diff --check` 通过。未改收银页面、其他业务源码、数据库或远程实例。
- 注释随本轮关键分支同步补齐：`AiGatewayServices.php`（公共比较词脱敏保护、已签名排行详情快速路径、重复排行处理）、`AiIntentUnderstandingContract.php`（完整日期恢复边界）、`AiRegisteredPlanCompiler.php`（空条件重放）、`AiConditionResponseFormResolver.php`（严格比较词）、`AiExactRankingCollectionAdmission.php`（注册人员首名）、`AiSemanticIntentParser.php`（“到店”与日期连接词区别）。
- 引用扫描：现有模型理解及对象详情路径仍服务于混合追问和不确定问句，未被本次窄入口完全替代，不能删除；本次临时定位日志已移除，不保留平行实现。
- 仍待验证：瑞昊上线后同账号、同一会话的乱序重测；线上 25 秒目标；Excel 文件逐格内容；原复测报告中的全店范围风险（#31 等）不属于这四项修复，不能声明整个 R55 用例全绿。

## 本轮文件 SHA-256

| 类别 | 路径（相对 `美容源码/`） | SHA-256 |
| --- | --- | --- |
| 后端 | `后端代码/app/services/ai/AiGatewayServices.php` | `4917323974cd8468b5da0f3dd8049ab426a5d1b6114f036c77494aef55c8e4f3` |
| 后端 | `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `712a0414ccb1347e81fe3cc9a0e97a4f5860b4d15a499d2f6642576833e7e465` |
| 后端 | `后端代码/app/services/ai/execution/AiRegisteredPlanCompiler.php` | `e7282004dd5718d358ab0c718f784a0e60b1606fbc33ab142a35ee6c9c6d36d0` |
| 后端 | `后端代码/app/services/ai/semantic/AiConditionResponseFormResolver.php` | `c88febdef30e726dea5d9c15bfaf79548efe7928d2eaf623868c918f68dc0125` |
| 后端 | `后端代码/app/services/ai/semantic/AiExactRankingCollectionAdmission.php` | `d33d2f95516a41ac4803e76726b1e0476f6a9f8d4978a8d7080e3ce12601b239` |
| 后端 | `后端代码/app/services/ai/semantic/AiSemanticIntentParser.php` | `127abe65757cd410f1dd4ddfa20c751355272026b01c84d58cc3f2fbdb0580ae` |
| 测试 | `tests/mohe-ai/condition-set.php` | `4b983634683082e505b2c18043aa7457792b0d900ab3a796b4c8457812f74305` |
| 测试 | `tests/mohe-ai/exact-ranking-collection-admission.php` | `5e6bc921721ba07d77ef26999f1a26e20b789b4426149f7ea48d2c11920ccb42` |
| 测试 | `tests/mohe-ai/object-detail-continuation.php` | `d03437542adcee9a6eb95ea3967b72fc700f7431e6f9a4229795593f4d65015c` |
| 测试 | `tests/mohe-ai/r50-calendar-range.php` | `8dca409cf1c77d17aeee7882d5af7a6ec3f9a0bde48a80f393a0f495ff355c73` |
| 测试 | `tests/mohe-ai/registry-execution.php` | `1d3c00cb80986864be69b271a46416072bb499dbeb4f580a9783e51aef2b45dd` |

固定版本的文件内容或补丁指纹变化时，须重新记录并复测受影响范围。
