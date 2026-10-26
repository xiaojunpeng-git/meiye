# R54 对象详情字段：本地开发与自测记录

## 固定范围

- 基线 HEAD：`a3760505971e3cae7e471694cb9af68526ef40ad`。
- 本轮补丁指纹（下列已跟踪文件的 `git diff --binary`）：`ac154453d7cd5953667c06b92b20ece6d1622a3c139a47c4b5958fb6e03e1fb0`。
- 新测试文件 `tests/mohe-ai/r54-detail-profiles.php` SHA-256：`fdf32fa414f4e7ed35081e391e7d1e5845fd0e313beaec00b2cbad97e7217f36`。
- 下表为文件完整内容 SHA-256；本证据文件自身不纳入固定代码版本。工作区其他任务的脏文件不属于本轮。

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/services/ai/AiGatewayServices.php` | `fbfb01bbbdb65cb9dcd97f51901e428ecba492adb093ed7ce83f7f3902685946` |
| `后端代码/app/services/ai/execution/AiRankingPresentationMetricResolver.php` | `88d4d2d4089c7647fc69645dd41415a8192337f80540ac188234c9b04d0b02a6` |
| `后端代码/app/services/ai/presentation/AiMemberDetailAnswerRenderer.php` | `f3fde812671a4fc7824a436b8d1add37571938e5449edcf24b179ed4987a8914` |
| `后端代码/app/services/ai/presentation/AiMemberRightsExportProjection.php` | `8f1818459364c63ec45705084da01e73e1e1cd1d2370630f62f4fe489603cd7f` |
| `后端代码/app/services/ai/semantic/AiExactRankingCollectionAdmission.php` | `64a4017071be01ae3661109e5261e51b0f5a4c68af0e91db032c345eabf61f58` |
| `后端代码/app/services/ai/registry/AiBusinessRegistry.php` | `adb5fe219332bc209715471036489f2aa338bfbcd6b25218ab804ef6301192b9` |
| `后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php` | `0ed0a39b5c0c5e8d580f3b759697b362936faf7bc94e4cf458f2af4d53693b65` |
| `后端代码/app/services/metric/MetricDictionaryServices.php` | `c5e853eca0eb2979bebc69111259468d00ba975b8433f09bced9f92dc078770c` |
| `后端代码/app/services/query/metric/MetricDefinitionRegistry.php` | `5fe748fad7a7f4bb7b493150d708378f23e19cf2a88b088632da7fb729f1acf5` |
| `后端代码/app/services/query/metric/MetricReadViewServices.php` | `d71eb5a8cedbe2bcf60dbbbc67390359948399e5d4981f2b9c684ad817a47cfe` |
| `后端代码/app/services/query/metric/RegisteredMetricReadServices.php` | `fb7ed0427ade418f71e155f07a4db00b6a16858d51941e3c327e1a1007f8f4fa` |
| `后端代码/app/services/query/metric/ServiceCustomerMetricReadServices.php` | `3c9ad923235f7d4eb92e0679b87585468a3a71ae493681486f9dc65243810f79` |
| `tests/mohe-ai/condition-set.php` | `2aa4ba9b34ab2f63d4c1b9faa2da88d16dd8db1f64aa1f5ddc357547aa1dde47` |
| `tests/mohe-ai/member-detail-continuation.php` | `e640456fd02ba2b823438094fbfa192d907ea1c4ae477f0b16377816c2e91fb7` |
| `tests/mohe-ai/object-detail-continuation.php` | `6e2147ba1f3067d5ddd8cdf03385f8377dd51df9877e864ccdac8554035f8ac8` |
| `tests/mohe-ai/personnel-analysis.php` | `4bc2f2ca266dd562373544827342c57ce10fd4087a03cc44e110338f4ebe4671` |
| `tests/mohe-ai/exact-ranking-collection-admission.php` | `ca4955db163708da0e74279b71d9527c51c5946e1dfbf3ef691fa2e099399793` |
| `tests/mohe-ai/gateway-review-regressions.php` | `905d97b8200a86f7d6e0c33707c32f0fa96473ef0dc5346305a9c41fb33ff7ee` |

## 实现与事实边界

- 会员条件筛选后的“客户详情”保留筛选集合，再读取本人账户余额、有效卡项与剩余项目；展示权威会员所属门店，不用操作人门店冒充归属。
- 员工详情由同一注册表声明六项：销售人业绩、劳动业绩、项目数、手工费、服务客次、点客次。手工费取有效劳动分配事实的手工费字段；点客次取完成服务及有效点客分配，同客户/门店/日去重；普通服务客次按共同服务人员分摊，保留十分位。
- “最高的员工详情”属于一个有界排行加详情请求；只在唯一注册指标、对象、方向和完整句子残余均可验证时走一次查询。注册快照与 Reader 共同验证六项详情，不接受任意多指标请求。
- 项目消耗金额**尚未启用**：本地抽样检查的 10 条直接购买后完成服务，10 条均缺少可对账的消耗业绩事实。不能将销售额或服务金额静默替代消耗金额。若要补齐，需单独决定是否扩到收银事实写入与历史回填；本轮没有改动收银代码或历史数据。
- 本轮修改的复杂业务分支已补职责和口径注释，涉及上述 12 个后端文件；未发现可安全删除且由本轮完整替代的旧分支。无数据库迁移、前端源码、工程脚本或生成包。

## 验证

- 12 个后端 PHP 文件 `php -l` 通过；本轮差异 `git diff --check` 通过。
- 自动化：指标注册 96、注册执行 103、条件集合 91、会员追问 31、对象追问 37、人员分析 56、精确排行准入 40、详情画像 5、网关回归 85 项检查全部通过。夹具测试不能替代真实业务验收。
- 本地 Docker、授权账户、真实 Reader：会员条件计数后追问“客户详情”返回 1 个筛选对象及其当前权益（约 13 秒 + 19 秒）；员工先排行后追问“第一名员工详情”返回六项（约 12 秒 + 13 秒）；新对话直接问“本月销售人业绩最高的员工详情”返回完整六项，修正快照契约后约 6 秒、修改展示名称后复测约 4 秒。服务客次显示 `0.9客次`，点客次显示 `0次`，没有把分摊值取整。
- 首次复合问句曾因模型漏掉详情要求失败；精确准入后又发现注册快照漏带字段顺序，已分别补测试并在真实查询中复验。测试期间仅重启本地 `mohe-ai-execution-worker` 以加载新 PHP 类；未触碰线上实例。

## 门禁状态

开发与本地自测完成，待产品经理对上述固定版本确认验收。未 commit、未部署、未 push、未上传体验版。项目消耗金额另待业务范围选择，不在本次已完成字段之内。
