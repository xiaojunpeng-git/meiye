# R49 连续问答理解与结果提示优化

## 当前状态及版本

- 基线HEAD：`98005c1728baa92b6b37d6e106542c694fa0ab2e`。
- 固定验收版本：R49-local-01；已跟踪闭环文件补丁SHA-256：`8d5ef18412b9e273c4782325f9ab44011be192289395aa5e50758f91de2ec7f0`。新增集成测试及其余文件分别见SHA256SUMS.txt；代码变更后需重新固定。
- 本地开发与电脑端实操已完成；产品经理固定版本验收待确认，尚未commit、部署或push。
- 手机端按用户既有要求保持瑞昊线上，不切换本地。R49后端尚未部署，不能将当前小程序旧版或电脑响应式测试冒充R49手机验收。线上小程序待验收/部署授权后继续；实体手机最终验证由产品经理反馈。
- 本地源码及运行目录一致；仅重启本地魔核AI执行worker加载修改，操作前无在途任务。未改业务库、配置、数据库迁移或其他模块。

## 完成内容

1. 快捷排名复用可信前文的共同时间及门店范围；显式日期优先，新对话保留既有今天默认值。切换对象/指标/排名不携带旧对象条件；具体选择或不一致上下文回到原理解路径。
2. 已登记的员工默认参与集合随指标替换，不误当具体选中员工；依据注册元数据判定，无固定问句/客户判断。
3. 会员排名的合法空分组直接发布正常空结果；缺失ID、名称或损坏分组仍拒绝。零金额行和不足五名保持真实结果。
4. 项目、产品详情复用已有概览指标；自然语言集合追问复用原有身份、数值和有效期。
5. 空结果/无法确定详情的提示改为用户能理解的表述，沿用双端共享后端结果。

## 电脑端实际操作（本地平台账号admin）

实际使用工作台输入框输入并发送，检查页面最终结果；保留原失败对话，不删除记录。

| 问题/场景 | 实际结果 | 页面用时 | Run |
| --- | --- | --- | --- |
| 原项目排行后问“会员业绩最高的前五名详情” | 继承2026-09-01至09-27，5名会员排名及权益汇总 | 2秒 | fbe091ccccce05ae0c45facca1d78020c6a66a0582d650ee |
| “今天会员业绩最高的前五名详情” | 09-27无记录，正常空结果，未读取会员权益 | 1秒 | c9746e15b3920200213255a2675af456a9687d6e393472b6 |
| “这个月门店业绩最高的前五名详情” | 5家门店，现金/消耗/退款/实际业绩 | 2秒 | 388397965bdbb1d2e4b3d2e0763558b21eb54544c4ab181b |
| 接问“员工业绩最高的前五名详情” | 同月5名员工，销售人业绩/劳动业绩/项目数 | 2秒 | 88cbb12343d9611c63d4e5987d44f3ea5d734ac4b9c9ee29 |
| 接问“项目业绩最高的前五名详情” | 同月项目排名及既有销售/完成服务数量，未夹带员工集合 | 3秒 | 436081e9903cf2984679be957ea8765af3d2aa92207f76bf |
| 接问“产品业绩最高的前五名详情” | 同月产品销售额及销售数量 | 3秒 | 1ec6c5ed243ac4590aefc677f5a284d5f7674fa0f54fe12c |
| “这几款的详情再展开看看” | 原5款产品、数值及期间保持一致 | 6秒 | 72ffa252257c873ca0facf74a0c04f0cd69205c299d57ea0 |
| 新对话：“会员业绩最高的前五名详情” | 默认09-27，正常空结果，不继承旧月份 | 1秒 | 6c32fe4bd652cf42fed1a69e6bff4993c045c5f9508f22e5 |

初测发现并修复：员工转项目原先19秒后AI_UNSUPPORTED_CONDITION（6d82fe67680425a51b1e5399d6c0a3bd3782e4fd54c2e8f0）；产品自然追问原先8秒后AI_OBJECT_DETAIL_NOT_READY（c51ff7411691b8339195a3702e24a529289e8e3a515a5f9f）。失败记录保留，上表对应复测成功。

页面秒数为界面显示值。客户端毫秒记录分别约2641、1386、2287、2530、3710、3681、6872ms；明确排名使用0次模型，产品自然追问使用1次模型（约5045ms）。样本用于本轮回归，不代表全量P95承诺，也未提高超时阈值。

## 自动化与真实数据自测

- exact-ranking-collection-admission：36通过。
- member-detail-continuation：30通过。
- object-detail-continuation：35通过。
- query-context：64通过（协议夹具）。
- context-delta-gateway：88通过（SQLite及合成模型/事实）。
- gateway-review-regressions：85通过，0失败。
- r27-ranking-presentation：3通过；temporal-ranking：5通过。
- R49本地真实数据只读集成：34通过；五种对象的连续切换、日期、行数、展示/导出列数、原详情身份及有效期一致。
- R48本地真实数据只读集成：37通过；门店与员工附加指标独立单对象查询对账、导出及权限契约回归通过。
- 7个后端文件PHP语法检查通过；git diff --check通过。
- 自动化结果不替代产品经理验收，也不冒充小程序实操。

## 文件及源码治理

前端源码：无。数据库迁移：无。工程脚本：无。构建生成物：无。

后端源码（均同步补充/更新职责及关键边界注释）：
- app/services/ai/AiGatewayServices.php
- app/services/ai/context/IntentContextMerger.php
- app/services/ai/context/MemberDetailContinuationResolver.php
- app/services/ai/context/ObjectDetailContinuationResolver.php
- app/services/ai/execution/AiRankingPresentationMetricResolver.php
- app/services/ai/presentation/AiAnswerRenderer.php
- app/services/ai/semantic/AiExactRankingCollectionAdmission.php

自动化测试：exact-ranking-collection-admission.php、member-detail-continuation.php、object-detail-continuation.php、gateway-review-regressions.php；新增r49-ranking-context-local.php。

验收证据：本目录README、SHA256SUMS、截图及运行记录；主用例表在原C05/C06/E07补变体，不新增重复主用例。

清理：删除旧外层empty(rows)判断、被共用行解析替代的重复分支及失效“无日期仍完全自足”注释。现有仍有调用的兼容路径保留，无新增平行服务/缓存/规则框架。其余任务脏文件未修改、未暂存。
