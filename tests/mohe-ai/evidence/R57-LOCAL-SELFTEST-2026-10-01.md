# R57 表格前 20 条展示、完整 Excel 本地自测

## 固定版本与范围

- 基线 HEAD：`02b375a717ecd6d8a628109f03f9116fb63180a3`
- 下列 17 个源码／测试文件的补丁 SHA-256：`2b3cc88eb767f2abe0bafb502749ffcc371bf5785e474369d9ecd78dda9ef1ca`
- 本记录仅描述本轮改动；工作区中的其他任务脏文件、未跟踪文件均未改动、暂存或提交。
- 状态：本地开发与自测完成；尚待产品经理对本固定版本确认验收。未 commit、部署或 push，未进行线上／实体手机验收。

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/query/metric/MetricReadViewStore.php` | `df948f9c8aaa1613d838545c43df029a567363e17f990a88b1b7bdf02e88d703` |
| 后端源码 | `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `ce436bdf80ede3f8a6674a1ecba5c7a41cd82e243b6c8f0d1d781ac00d2fa58b` |
| 后端源码 | `后端代码/app/services/query/metric/MetricReadViewServices.php` | `10925a431065dacd122c0fc565749182cb92ca8b22e305cf2103ae2df4bf3bbb` |
| 后端源码 | `后端代码/app/services/query/metric/MetricReadViewExportProvider.php` | `49b6dd363c53d546c4816c0572e0a944e8ba11728bf9fc83339275b4f57196a6` |
| 后端源码 | `后端代码/app/services/ai/presentation/AiAnswerRenderer.php` | `7fa666f0db07c45659d08288807a6d4e72e654b79f5e54e7051f365c032643e5` |
| 后端源码 | `后端代码/app/services/ai/execution/AiExportRuntime.php` | `120aee82771c1f94c51855996abac592e05d9a2d91458b89291447d869d53124` |
| 后端源码 | `后端代码/app/services/ai/AiGatewayServices.php` | `4edbddae828d45b8562192cf6af0d55fd3b67ec9fe1d7b681287efc457f820f4` |
| 前端源码 | `前端代码/shared/mohe-ai/browser-entry.mjs` | `fbb4e36ba24339ffd01e0d9e6abdfcc773847448c22b3c1556e0aac01595efa1` |
| 前端源码 | `前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue` | `243734af81741bd26d8827b5fbf2a268673dce9b9b3f9af20de6836b297a4f20` |
| 自动化测试 | `tests/mohe-ai/breakdown-query.php` | `d2763d29f6c1ab22a14224c7fbdaaa2c517a15f9789447b9b13167846de63dbf` |
| 自动化测试 | `tests/mohe-ai/condition-set.php` | `3da482153184d3c95797f3352b2beb346e566194f5aa84a3249005c6f7f18bb9` |
| 自动化测试 | `tests/mohe-ai/query-export-runtime-mysql.php` | `4f04cd3ede40d245069e86259584af6726b713cc1faf60f45c2ea0d577c81371` |
| 自动化测试 | `tests/mohe-ai/query-mysql.php` | `535d451f06c26b636f43b293b99605c03ca1641fd2fcf95899bfdebe98af9de4` |
| 自动化测试 | `tests/mohe-ai/query-read-view.php` | `5a5c1581725ba73feee2fc73c0d60d1382b773f4dba655092e88e5d1c7c43eae` |
| 自动化测试 | `tests/mohe-ai/browser-independent-export-contract.mjs` | `bd66103a30a3138eae91b0608c56703f56785e71230e74b01306731dbe45b9ad` |
| 自动化测试 | `tests/mohe-ai/browser-mobile-guidance-contract.mjs` | `b3a8ab320ff2208381bd938722625ea7aeb1f60134b11c18e78641b5278568e0` |
| 自动化测试 | `tests/mohe-ai/member-detail-continuation.php` | `04d1576a551178f5034f2146ed5c1c60f11cec1fc9406df4041da12ec42e7e37` |

本轮数据库迁移、工程脚本、生成物：无。验收证据：本文件。上述源码均补充／更新了完整明细、权限复核、屏幕截断与异步导出边界的注释；已移除被新展示路径替代的旧 `has_more`／预截断与未使用计数变量，未触碰其他业务模块。

## 业务与技术验证

1. 页面仅截取最终表格的前 20 条；0、20 条不触发自动文件，21、100、101 条触发。总数、统计结论不从页面前 20 条反算。
2. 完整明细在同一已签名、带权限约束的 Reader 结果中冻结；独立文件任务逐项复核访问权限和原查询引用。多段结果合并同一 Excel，保留段落名称与完整行，不重新查询最新事实。人工点选 Excel 与自动生成复用同一任务。
3. 超过单次安全完整范围（1000 条或签名视图 4 MiB）明确要求缩小条件，不能显示部分结果却声称是完整 Excel；现有部分历史视图也不标记为可完整自动导出。
4. 电脑端和小程序端均在答案可见后启动独立导出，不阻塞首答；导出失败由既有文件任务状态单独反馈。没有加入新的前端指标计算、第二套导出队列或关键词特判。

执行结果：

- `bash tests/mohe-ai/run-query-mysql.sh`：通过，含临时 MySQL 5.7 的 173 项 Reader 检查、真实隔离导出工作流、并发和管理回归；无客户数据库操作。
- `php tests/mohe-ai/breakdown-query.php`：63 项通过。
- `php tests/mohe-ai/condition-set.php`：122 项通过。
- `php tests/mohe-ai/query-read-view.php`：65 项通过，含签名视图超 4 MiB 的明确拒绝。
- `php tests/mohe-ai/gateway-review-regressions.php`：85 项通过。
- `node tests/mohe-ai/browser-independent-export-contract.mjs`：通过，含手动和自动触发。
- `node tests/mohe-ai/browser-mobile-guidance-contract.mjs`：181 项及新增自动导出断言通过；是小程序控制器夹具，不是实体手机验收。
- 其他 `export-runtime-contract.php`、`result-reference.php`、`member-analysis.php`、`query-context.php`、`mobile-ai-entry-transport-contract.php`、`browser-entry-contract.mjs`：通过。
- `php tests/mohe-ai/member-detail-continuation.php`：32 项通过；新增 68 位会员筛选与权益双表各只显示 20 条、导出快照保留 68 人／408 行权益的回归。
- 修改的 PHP 文件语法检查、`node --check`、`git diff --check`：通过。

### 本地浏览器复现与复测

- 首次复现时，本地执行进程早于本轮 PHP 修改启动，原回答仍显示 68 行；刷新执行进程后，新问答的两张表各只显示 20 行，但无 Excel 卡片。
- 检查本地 `18081/app.js` 发现没有 `auto_export_required`，说明平台热更新服务仍提供旧前端脚本；重启本地平台编译服务后，服务脚本已包含该字段。此处仅刷新本地开发进程，未部署客户实例。
- 再次于本地电脑页面原句提问“上个月消费有500元以上的会员详情”：当地数据本次返回 71 人（和先前 68 人有变化，不把旧结果当作同一固定数据快照）；两张表各显示 20 行。随后出现“Excel 正在生成…”，最终显示“Excel 已生成。下载 Excel”。文件任务不阻塞答案。浏览器自动化下载事件等待超时，故本次未宣称实际下载后的 XLSX 二进制检查通过；完整快照由上述 68 人夹具及独立导出工作流测试覆盖。

## 尚未宣称通过的项目

- 小程序工程整体 `npm run check` 仍因既有缺失商家文件及 `manifest.json` 不一致等本轮范围外问题失败；本轮生产控制器合同通过，不以它冒充整体编译或真机验收。
- 全量 `run-all.sh` 遇到 `gateway-integration.php:162` 的日期夹具仍预期 2026-09，当前运行日期为 2026-10-01；单项实际已完成并返回 2026-10-01。该旧日历断言未改，不将全量套件标成通过。
- 尚未进行真实线上／实体手机测试，也未测生产容量与耗时；上线前按既有门禁验证文件下载完整性、权限及回答时延。
