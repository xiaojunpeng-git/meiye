# R50 完整自然语言日期与营业额口径

2026-09-27。基线 `d49495ea`；本轮仅魔核 AI 后端与回归测试。
用户已明确同意：本地自测完成后，仅提交、部署本轮 AI 修复至瑞昊，再测试线上小程序。

## 变更

- 统一日期证据解析：年月/中文月份/相对月份/完整起止区间；缺失的终点年份继承起点年份。
- 不完整日期证据不得覆盖模型已识别的区间；非法日期和倒置区间仍拒绝。
- 指标字典登记“营业额”为现金业绩别名；扣除退款等额外条件不进入简化快捷路径。
- 完整日期前缀与排名问句之间的逗号不再阻断已有注册排名路径。
- 删除原来分散的日期清理表达式，复用同一证据解析。四个修改的业务文件均补充职责/边界注释。
- 没有修改指标公式、数据权限、历史覆盖起点、数据库、配置或两端页面。

## 固定业务源码指纹

| 文件（后端代码/ 下） | SHA-256 |
| --- | --- |
| app/services/ai/semantic/AiSemanticIntentParser.php | 4a0812a69b0ba58c049801f710a088a80fec5517cf8df508f5577915e4533089 |
| app/services/ai/execution/AiWorkflowPlanner.php | 88a42a5e545f5c8c7b3d812f1f7677858657545c9b4537df36df7fd716a17657 |
| app/services/ai/semantic/AiExactRankingCollectionAdmission.php | b4b4779dfca0a91b844c8845be100d633ee907cc4bc0d474f4c9bbc952507c3f |
| app/services/metric/MetricDictionaryServices.php | 6c6cb301cd66996ce4b29b2f85b5d624af1a9f229eff62528eded21f71f8cee3 |

## 自动化与真实本地数据

415 项离线断言通过：gateway-components 140、temporal-ranking 5、deterministic-summary-admission 22、semantic-guidance 97、skill-semantic-projection 19、query-context 64、exact-ranking-collection-admission 36、r50-calendar-range 32。

`r50-calendar-local.php` 在 mohe-platform-app 运行：原句编译为现金业绩、2026-03-01 至 2026-09-27；覆盖范围保护通过；9 月对应问句使用真实统一 Reader 排名成功。仅生成临时查询视图，不修改业务事实。

## 电脑端页面实操

本地 `18081/admin/setting/mohe-ai`，实际输入、发送、读取返回，保留历史。

| 问句 | 实际结果 |
| --- | --- |
| 2026年3月到今天，最高营业额是哪个门店 | 保留完整区间；因现有统一数据覆盖从 2026-08-10 起，要求确认可查询期间，未静默改为今天。服务端约 696ms 进入 WAITING_CLARIFICATION，命中 deterministic_registered_ranking_collection_admitted，无模型调用。 |
| 2026年9月到今天，最高营业额是哪个门店 | 页面 1 秒；2026-09-01 至 2026-09-27；合肥世纪中心店，现金业绩133,422元。 |
| 从今年九月一直到今天营业额最高是哪个门店 | 连续追问页面2秒；同样完整期间及结果。 |

截图 `desktop-month-ranking.png`。

## 不冒充通过的限制与额外发现

- 3月至今天的完整经营结果仍受既有历史覆盖起点限制，本轮没有补历史事实或放开该限制。
- 日期确认界面继续显示“正在思考/已处理”并累计等待时间；旧会话确认之后续查长时间未执行，测试主动停止，未删除记录。该既有异步确认/显示问题不属于本轮四文件修复，不能记为通过。
- 线上小程序待本轮部署后测试；开发者工具页面操作不等于实体手机验收。

## 交付分类

后端源码4文件；自动化测试为 `gateway-components.php`、`r50-calendar-range.php`、`r50-calendar-local.php`；证据为本目录。前端、数据库迁移、工程脚本、构建产物均无变更。其他任务脏文件未暂存。
