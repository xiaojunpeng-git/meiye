# R57 页面表格与 Excel 展示一致性本地自测

## 固定范围

- 基线 HEAD：`dfaaa5bc9b212ee5b044c9e47883393a314287ee`；本记录对应未提交的魔核 AI 专项修改，不包含工作区其他任务。
- 后端源码：`app/services/ai/AiGatewayServices.php`、`execution/AiExportRuntime.php`、`presentation/AiAnswerRenderer.php`、`presentation/AiMemberRightsExportProjection.php`、新增 `presentation/AiExportTableProjection.php`、`query/UnifiedQueryExportWorkerServices.php`、`query/metric/MetricReadViewExportProvider.php`。
- 测试源码：`tests/mohe-ai/export-runtime-contract.php`；无前端源码、数据库迁移或工程脚本修改。闭环已跟踪文件补丁 SHA-256：`fe71c4a6774283f3c578e2e5ba5513533d10ac8a844dfac6e3e52e7554b69f3c`（不含新增投影类；其文件哈希见下表）。
- 新增／更新注释覆盖：屏幕预览与完整导出边界、不可重读事实、Excel 展示页与精确数据页的职责、生成后逐格回读。旧长表投影仍用于精确数据与回读验证，不是无调用死代码。

固定源码 SHA-256（相对 `美容源码/`）：

| 文件 | SHA-256 |
| --- | --- |
| `tests/mohe-ai/export-runtime-contract.php` | `84f742c8fd20325d753b7886439f1c8d575c733f514eed97471b4bd7843dac04` |
| `后端代码/app/services/ai/AiGatewayServices.php` | `bbd8c711fe14e45569e0917fb5c0ecc95d6a443832e7ce7a4eea912f206794c6` |
| `后端代码/app/services/ai/execution/AiExportRuntime.php` | `7f33a1ddd828f0c01d9dd567af2ee0fe269b1bfa1a3e3766c16b075307e3c1f9` |
| `后端代码/app/services/ai/presentation/AiAnswerRenderer.php` | `c1cf665d76384d655fe820f3855e43148a78a0a0049de527a413f119da2fea39` |
| `后端代码/app/services/ai/presentation/AiMemberRightsExportProjection.php` | `9beadbeefb329da3b9ff0d9f9ff83d44ca0e904dfd9216fb28e0a9e4b3099ad4` |
| `后端代码/app/services/ai/presentation/AiExportTableProjection.php` | `6a28a8fee0824136ba0fe6d29230f31ed2750b2fe293cfcf76e02521911d029e` |
| `后端代码/app/services/query/UnifiedQueryExportWorkerServices.php` | `94588519d991ab651ab3978e0590303da4dcf9ed3e3c3972e89921e9e2b094fb` |
| `后端代码/app/services/query/metric/MetricReadViewExportProvider.php` | `3e2599754ae7b239dd4bef1c3ec5d498f57878ffc5015abb95606e11abd68805` |

## 本地真人路径

在 `http://127.0.0.1:18081/admin/setting/mohe-ai` 重新提问“上个月消费有500元以上的会员详情”。这一轮新答案用时 22 秒，筛选出 68 人；页面“会员筛选依据”和“会员权益汇总”两张表各预览前 20 条，随后显示“Excel 已生成／下载 Excel”。使用本地同一任务产出的真实 XLSX 文件逐格检查；没有复用旧回答或旧文件。

| 检查点 | 结果 |
| --- | --- |
| Excel 可见页 | `查询结果`；先“会员筛选依据”，再“会员权益汇总”；每段包含页面相同的结果概要、统计时间与表头 |
| 表头 | 第一段为“会员／指标／数值／单位”；第二段为“会员／所属门店／账户余额（元）／本金余额（元）／赠送余额（元）／有效卡项（张）／剩余项目（次）／剩余项目金额（元）” |
| 条数 | 页面各 20 条；Excel 两段各 68 条。Excel 可见页共 143 行（两段标题、概要、表头、68 条明细及段间空行） |
| 值与顺序 | 两段首行、页面第 20 行的各列均与 Excel 对应行逐格一致；服务端在文件发布和下载时还会对完整表逐格回读 |
| 精度 | Excel 的隐藏 `精确数据` 页保留 476 条原始指标明细及分位精度，不覆盖可见表的整数元展示 |

真实文件：`后端代码/runtime/unified-query-exports/2026/10/uqe_91a05b3c41e9642bc748088c60dc26c4-1c2600a133aabf43e780713dda8586924473b30ab9618f2284df6dacf3b4f466.xlsx`；大小 34,088 字节；SHA-256 `39b1ccd21aaee0c2db250c98f8214dfd88cd72e70b25ff2581b528d144582ef9`。该文件属于本地私有运行时，不纳入 Git、不上传或公开分享。

浏览器自动化的鼠标点击没有发出下载请求；用键盘在同一“下载 Excel”按钮按 Enter 后，网络记录确认该 Run 的 `/export` 返回 HTTP 200，类型为 XLSX。浏览器自动化仍未捕获 `download` 事件，本机下载目录也未出现新增文件；因此确认“按钮入口、授权下载接口、服务端文件及逐格回读通过”，**不把浏览器落盘下载记为已通过**。此前同一路径曾下载成功，这一 IAB 自动化落盘差异需在产品经理验收前关注。问答任务和导出任务均未部署瑞昊、未 push。

## 回归

- `php tests/mohe-ai/export-runtime-contract.php`：通过，含 21 人屏幕 20 条／Excel 两段 21 条、两段列名与顺序、整数元与隐藏分位原值、旧版快照兼容，以及对导出可见标题的篡改拒绝。
- `php tests/mohe-ai/export-worker-contract.php`：通过。
- `php tests/mohe-ai/member-detail-continuation.php`：32 项通过。
- `php tests/mohe-ai/breakdown-query.php`：63 项通过。
- 7 个范围内 PHP 文件（6 个修改、1 个新增）及 1 个修改的 PHP 测试文件均通过 `php -l`；`git diff --check` 通过。
- `query-export-runtime-mysql.php` 需专用可销毁 MySQL 测试实例，当前环境未提供，本轮没有冒充其通过；真实本地问答与实际 XLSX 回读已覆盖本次主要链路。

## 发布边界

开发和自测完成，待产品经理对这一固定文件版本确认验收。未 commit、未部署、未 push、未上传体验版。旧会话／旧 Excel 不在本轮补处理范围。
