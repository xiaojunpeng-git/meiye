# R55 魔核 AI：本地固定版本与自测

基线 HEAD：`bdaec116`。范围仅限魔核 AI 的上下文条件、排行理解和相应回归用例；没有更改前端、数据库迁移或其他业务代码。瑞昊线上五个目标 PHP 文件在预检时的 SHA-256 均与本基线 HEAD 一致。
八个修改文件的 `git diff` 补丁 SHA-256：`8a21efc521b347b2a2674c35015e2651f8b7daa2d8dad86488b79fe69283ad40`。

2026-09-30：产品经理在本固定版本与自测结果交付后明确回复 `commit`，确认提交本轮成果。仅暂存本文件和上列八个代码／测试文件；其他任务及此前未归档文件保持原状。

## 本轮实现

- 完整新排行替换上一轮金额条件和已选人员；明确“所有／全部／全体”时移除上一轮私有门店筛选，最终范围仍由 Reader 的原有权限裁剪。
- 已登记指标的“前 N 家／名”等自然问法可直接编译；项目、卡项、产品集合排行的“前两名→昨天呢”保留集合结构、排行数量和指标。
- 完整新会员金额问句不误继承旧条件；“只看本月，条件不变，有多少人”保留已签名比较符及阈值，只更换统计期间。
- 修改的公共方法和关键分支已在 `AiGatewayServices.php`、`IntentContextMerger.php`、`AiIntentResultContract.php`、`AiIntentUnderstandingContract.php`、`AiExactRankingCollectionAdmission.php` 同步补充职责与边界注释；未保留已替代的旧筛选分支。

## 本地真人页面自测（2026-09-30，同一开发源）

| 链路 | 页面观察 | 结果 |
| --- | --- | --- |
| 本月项目卖得最好 → 项目、卡项、产品分别最好 → 前两名 → 昨天呢 | 1、2、4、2 秒；末问仍是三组排行、前两名、昨天期间 | 通过 |
| 年度会员消费 >980 → 改成 >1980 → 只看本月、条件不变 → 独立完整本月 >1980 | 2、2、2、2 秒；后两问人数与期间一致 | 通过；正式人数仍须线上 Reader 核对 |
| 产品销售额≥5000 且销量≥10 | 14 秒，按两项条件回答当前日期的结果 | 通过执行路径 |
| 本月产品销售额前五名详情 | 1 秒，显示前五名和登记指标 | 通过 |
| 本月项目业绩前五名详情 | 2 秒直接按项目默认销售额列出前五，无多余指标确认 | 通过 |
| 本月所有员工业绩前两名 → 第二位具体看看 → 哪个员工业绩最高 | 11、9、5 秒；第二位详情准确，末问回到全员排行 | 通过 |
| 指定门店现金业绩 → 3 月至今所有门店现金业绩前两名 | 16、1 秒；末问结果不再限原门店 | 通过 |
| 指定门店 → “不要只看该店，统计我有权限的全部门店”前两名 | 20、2 秒；明确全店问法移除了上一店，仍只查询授权范围 | 通过 |
| 勾选 Excel 的门店前两名 | 答案 2 秒，文件最终生成；此前首次在旧本地 Excel worker 下失败，重启 worker 后通过 | 通过；部署须同批重启 Excel worker |
| 这个月经营情况对比上个月 | 4 秒直接展示多项已登记经营指标的本期和对比期，没有要求先选单项指标 | 页面通过；对比期数值仍须统一 Reader 对账 |

本地重启了 `mohe-app`、`mohe-ai-execution-worker`、`mohe-ai-export-worker` 以加载 PHP 变更；没有改动瑞昊线上服务。

## 自动化与已知限制

`condition-set.php` 113 项、`exact-ranking-collection-admission.php` 50 项、`context-delta-gateway.php` 93 项通过；相关语义、意图、注册执行和网关回归测试此前也通过。`git diff --check` 与修改的 PHP 语法检查通过。`bash tests/mohe-ai/run-all.sh` 在既有 R5 holdout 的 13 个预存不匹配处退出，不能宣称整套全绿；本轮未修改 holdout 或其夹具。

本地页面通过不等于瑞昊线上、手机端或完整 77 条乱序用例通过。线上验证应在同一新会话执行全部用例，并记录无法回答和超过 25 秒的问答；异常需与原始 `R55-RH-SINGLE-SESSION-RANDOM-ONLINE-2026-09-30.md` 比较。

瑞昊部署预检（只读）：目标 `/www/wwwroot/rh.cc3798.com`，现有五个目标文件都与基线 HEAD 一致；服务器 PHP 7.4.33，`mohe-ai-execution-worker@1`、`mohe-ai-export-worker@1`、`mohe-ai-supervisor` 均为 active，备份目录 `/www/backups/rh.cc3798.com` 可用。本轮无迁移、无跨实例共享修改；只允许从本轮 commit 提取五个 PHP 精确覆盖，部署前在该备份目录创建独立批次并保存原文件，部署后同批重启 Swoole、执行 worker、Excel worker、Supervisor，逐文件验哈希；失败则从该批次恢复五个文件并重启。通用 `deploy-backend.sh rh` 会同步整个脏工作区，不能用于本轮精确发布。

## 固定版本 SHA-256

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/ai/AiGatewayServices.php` | `5d994abd259bacb720f473024cfada41efd86a64b860ddffc663b036a7dbfc19` |
| `后端代码/app/services/ai/context/IntentContextMerger.php` | `183491d27a151f9f70e502b26d6ef5832aa7fd3d8b15af16c73a3e8880fc07db` |
| `后端代码/app/services/ai/contract/AiIntentResultContract.php` | `6ab1617c6bf8721b691145db357a52dc7705e375dd6c909dc5d55dd8ab7f73b7` |
| `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php` | `e2d44e586522217df42cea8f9fd338f02136a9f70ace5d0cfa986b8f4adc0e57` |
| `后端代码/app/services/ai/semantic/AiExactRankingCollectionAdmission.php` | `3512cc22313ecd3dd2162182537340d587bddb9f5fc3357580aad15094fa196c` |
| `tests/mohe-ai/condition-set.php` | `b54469156e7ceb688fda7a9725db070b10cf3e0142e08e4981c3224876809535` |
| `tests/mohe-ai/context-delta-gateway.php` | `7c32bf3249a122ca057125b7c6bb924265082795f159988f67712dc05ec2063d` |
| `tests/mohe-ai/exact-ranking-collection-admission.php` | `3cab6444d70fc3f1463664231559ea1f31f86e6b35354d846e0709c64ca9d30d` |

前端源码：无。后端源码：上述五个 PHP。数据库迁移：无。自动化测试：上述三个 PHP。工程脚本：无。生成物：无。验收证据：本文件；上线后的独立证据另记。
