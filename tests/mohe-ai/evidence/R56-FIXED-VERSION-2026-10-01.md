# R56 来源会员人数与详情：固定验收版本

- 基线 HEAD：`58cbca990c364426a8e07a9e991566f0d48dbcf9`
- 范围：下表 23 个文件的当前工作区内容；无数据库迁移、前端源码、生成物或线上发布。
- 指纹算法：按下表顺序对每个文件运行 `shasum -a 256`，再对输出整体运行 `shasum -a 256`。
- 文件集合指纹：`44f30cc8fa0058f78a6071b552a7352777e89c1ac144a9c9ad5c9eae2b99d563`
- 已跟踪且本轮独占的 14 个文件的 `git diff --binary` 补丁 SHA-256：`cc272c5b1d2eb0b48de3e262b545740a86ad9e6f83343e9fae02ec4b10f63583`；新文件和原先已混改的长期用例文件以本表内容指纹固定，避免把他人补丁误认成本轮补丁。
- 验证与业务口径：`R56-SOURCE-LOCAL-SELFTEST-2026-10-01.md`。本表不代表产品经理已验收。

| SHA-256 | 文件（相对 `美容源码/`） |
| --- | --- |
| `9700a63a71a3e0519a98dcdc8c5c7ec31d8a5e90a07a1307ceb8714d63469f3d` | `后端代码/app/services/ai/AiGatewayServices.php` |
| `422d043c64f7d536160a8f67bbecf4c1bc7b18ed57d75adef66ac3a81e4a9e1d` | `后端代码/app/services/ai/execution/AiRegisteredPlanCompiler.php` |
| `b7e3439d88a952da52ef8ff348ef7c0042de324f1938d37abb646302ee57ff9e` | `后端代码/app/services/ai/presentation/AiAnswerRenderer.php` |
| `de899ef63b4c36d7578c3b4a2a803adf14d21612e7dbf4e1a46a5264976e3070` | `后端代码/app/services/ai/semantic/AiFirstCourseSourceAdmission.php` |
| `e4a304c57124b1cffb64c3e68d6a05fbad405228916cf94018becce50166062d` | `后端代码/app/services/metric/MetricDictionaryServices.php` |
| `13dd82d9e63930db91ceef1e466e030af641162ee8a377ee07dc6bdfa88ef66e` | `后端代码/app/services/query/metric/FirstCourseSourceCatalog.php` |
| `5993f6668da1521687ea80d0f48e5bd89047540a96ffbf58b0310cdceaa28f20` | `后端代码/app/services/query/metric/FirstCourseSourceFilter.php` |
| `acf0f8b6a09fe0bc7223072e33f98950ff026f3cbac56ee8ad625b6732298d1b` | `后端代码/app/services/query/metric/GroupPerformanceMetricReadServices.php` |
| `b9a1236660182fc07ddb53422b23e95aaffbf8456a1b46d2b5f13207412e2320` | `后端代码/app/services/query/metric/MetricDefinitionRegistry.php` |
| `41170ae9b0354c1e35cfbda897d333fb7d140c70cead114b2fcc59d3327d35df` | `后端代码/app/services/query/metric/MetricReadTransaction.php` |
| `0ab4e88e93ce4af946c2edaa4ae999fbbfe228b22167a0619f6db58e19889016` | `后端代码/app/services/query/metric/MetricReadViewExportProvider.php` |
| `13fd08dd8d67a84599b9db1b50b31cae02ade33fd13152e7c1aecd0974a0751c` | `后端代码/app/services/query/metric/MetricReadViewServices.php` |
| `2c376dab56b02389c700ec743a3bd39734afc7edbe9bebd8112f7983b49d4dae` | `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` |
| `4157b50d2d7d8c088a5d663c72b275d4ca3f25b21d6e579578ea805975ff6919` | `后端代码/app/services/report/CustomerAnalyticsServices.php` |
| `1112b9e63138823fc36aeee6ffcff523e50fe3ae0391487a98b20125312214e5` | `后端代码/app/services/report/FirstCourseMemberCohortServices.php` |
| `eacee49587bf4f0a6635f01ef59bcc49387c2f72f9e27dd83c0bdd6b47c5ec6a` | `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php` |
| `70e16977c527a9ac542b3147ae196203b3d57d5c0c21a17d234268dd7a216f22` | `tests/mohe-ai/query-mysql.php` |
| `3eff4417968bab26f1bfbd23e7da3743d00806b506376ef3f0faa70e31bd5dd4` | `tests/mohe-ai/query-context.php` |
| `7804e1343bb401092b37bd89df8c0c7a7d383a67ffaf090171db2bff8e667ad1` | `tests/mohe-ai/r56-source-gateway-local.php` |
| `9112e5a5e1f0e94e948d13a113c3fc9e518e2c9d67b7524b89f0b2fe54e93783` | `tests/mohe-ai/r56-source-local-readonly.php` |
| `f61d6363287dd15f1a8b78f98d816aecd042725affdc407703fa33a27631e92d` | `tests/mohe-ai/MOHE-AI-ROUND22-29-TEST-CASES.md` |
| `bd8cf15c94325594ba79173461ce9aa1928c2714c953fd29f854050556e12432` | `tests/mohe-ai/evidence/R56-SOURCE-DATA-AUDIT-2026-09-30.md` |
| `d9aa43040b0be713dc16e06d378bdc554e6a0bf26a10062064aabaa920a894e6` | `tests/mohe-ai/evidence/R56-SOURCE-LOCAL-SELFTEST-2026-10-01.md` |

`MOHE-AI-ROUND22-29-TEST-CASES.md` 在本轮开始前已有其他改动；以后获准提交时仅纳入 R56 D07 相关补丁，不暂存该文件的其他脏改动。固定验收期间若上表任何文件变化，应重算指纹并说明受影响的补验范围。
