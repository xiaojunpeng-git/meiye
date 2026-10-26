# R51-PC-01 固定候选版本

状态：本地开发、自测及电脑端实际页面路径完成；待产品经理对本固定版本确认。小程序仍连接瑞昊旧后端，尚未通过新版本实际验证。未提交、未部署、未推送。

基线 HEAD：`86760d6a494c2660d9617efd9dd498fbeeadf772`

源码和测试补丁 SHA-256：`88059b8c7561e20bf6c8697fe2bff54538c31bf171ca3fd697e355bfbb72f83d`

## 范围与业务口径

单数沿用项目数；门店范围与技师分析对象分离；唯一结果指代保留稳定门店 ID；命名门店不被同名会员片段误拆；最高/最低各一名并保留并列；日期追问继承指标、门店与技师范围。只改魔核 AI，不含其他任务改动。

## 文件分类

- 前端源码：无。
- 后端源码/运行技能：下表的后端代码文件（共 9 项），新增/修改关键约束均有注释。
- 数据库迁移：无。
- 自动化测试：下表的 tests/mohe-ai 文件（共 4 项）。
- 工程脚本：无。
- 构建生成物：无。
- 验收证据：R51-IN-PROGRESS-2026-09-28.md；R51-live-named-store-failure-2026-09-28.png；R51-live-named-store-pass-2026-09-28.png；R51-live-original-pass-2026-09-28.png；R51-live-date-followup-pass-2026-09-28.png；R51-PC-final-2026-09-28.png。证据不作为业务源码。

## 文件 SHA-256

```text
684ff9bae08063108af70d7177b57c7f9554fab6f2f3f18ea5620c28f0128e05  后端代码/app/services/ai/AiGatewayServices.php
c327397cd542d0e44dd49505f4f62872fd814c53d385f4fc6af66633fd0ceb6f  后端代码/app/services/ai/context/IntentContextMerger.php
39b8cf06d72d4024a841cb515e918ac3065e60d1292fb1ffd7e62e8c08c793c0  后端代码/app/services/ai/context/ResultReferenceResolver.php
507d5b12bc0f4eccdf95441fb3b306e1267a57f46ff51269a7f0481405eccfe3  后端代码/app/services/ai/contract/AiIntentResultContract.php
6c31f7b916430ac688ebf36ec8b29eb69f9f69dc6c408c21f2000717a8ee52be  后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php
05bbc97935a52fbd236d7aaa7a4389053b70c8b6a8e45905e2820ea6dc88d273  后端代码/app/services/ai/model/SiliconFlowClient.php
1cb0ccef75d2ed0a4d1d19827fd386d6fe5c7be432889762473094fb67e2fd29  后端代码/app/services/ai/semantic/AiSemanticIntentParser.php
23856d32d03a72a3475d6a366c799482fa74a52c98eb75dcae93fc7183554d34  后端代码/app/services/ai/skills/intent_understanding/SKILL.md
dc5be5ea578073f3d56cb0de652279d255585e23ffdd1ea2835e791d963d9319  后端代码/app/services/metric/MetricDictionaryServices.php
5a85f1040d88f9c1486c34365e5547e17b8cda9292f4c2391fa8d41c887cf380  tests/mohe-ai/result-reference.php
dad51f43f4e8b935c40c4e4698ca14e66af96ca283797173ef64c6292dd8cc3f  tests/mohe-ai/intent-understanding-contract.php
4b258a4e9a465938f521ca70161ad6e5f55744855d3ac72dda72bb7b6c326db6  tests/mohe-ai/context-delta-gateway.php
74d24ac8c00538dfd42612fb349db4d3cded9f908e4f0b19d73935e1b106d4a9  tests/mohe-ai/metric-registry-contract.php
```

## 验证与限制

详见在途记录最后一节，所有失败证据保留。原句、点名换门店、只改日期、恢复日期通过电脑端实际输入/发送/查看，且服务端实际查询范围已核对。原句约 21～29 秒，日期短追问约 2 秒；模型阶段仍有延迟，不冒充全面性能达标。

手机端只做过旧线上对照，不能作为新版本验收。继续微信开发者工具验证前需按项目门禁确认本固定版本并授权部署瑞昊。本清单不是产品经理验收签署。

## 清理边界

未引入独立“订单单数”计算；此前在途独立计数路径已撤回，MetricDefinitionRegistry、RegisteredMetricReadServices、ServiceCustomerMetricReadServices 无本轮 diff。没有保留平行实现或创建源码副本。其他任务的现有改动、历史记录和测试证据均未删除。

## 产品经理确认（2026-09-28）

产品经理针对上一条固定版本交付及“仅提交、部署本轮魔核 AI 修复，再继续小程序测试”的请求，明确回复“确认，允许”。以上 13 项文件重新校验 SHA-256 全部一致；本地验收与本轮瑞昊部署已获确认。手机新版本验收仍待部署后实测，不改写为已经通过；本次未授权 push、体验版上传或其他业务发布。
