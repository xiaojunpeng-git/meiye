# R38 通用对象分组展示自测记录（2026-09-24）

## 固定版本

- 基线 HEAD：`2164bdebdce51591bb898b590f4a17ae2f62d227`
- 本闭环补丁指纹：`c36a24e69bdbe2ad6941a7dee0c37588f236ea60c5e66e64082209b867522396`
- 状态：开发与 Codex 自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push。

## 本轮范围

- 分组问数不再为门店编写独立展示分支；门店、人员、会员复用相同的对象维度契约。
- 宽泛“会员业绩”使用指标注册表唯一默认指标 `cash_performance`；用户明确指标时仍以明确指标为准。
- 回答先给有数据对象数和权威合计，再给一对象一行的明细；隐藏全零对象，首屏最多 20 个有数据对象。
- 负数属于有效经营证据，不能被零值对象挤出有界结果。
- 未取得完整分组证据时不汇总当前页，不把部分和伪装成总计。
- Excel 数据源不在本闭环扩大；页面不承诺当前导出能够覆盖未加载分组。

## 真人路径结果

本机平台端 `http://127.0.0.1:18081/admin/setting/mohe-ai`，瑞昊本地数据，统计日 2026-09-24：

1. `今天各门店业绩是多少`
   - 用时 34 秒。
   - 返回：1 家门店产生实际业绩，合计 70,571 元。
   - 明细只保留政和朝阳店 70,571 元，提示隐藏 101 家零值门店。
2. `今天各员工业绩是多少`
   - 用时 28 秒。
   - 返回：2 位人员产生销售人业绩，合计 36,038 元。
   - 明细为汤佳英 24,827 元、吴淑萍 11,211 元。
3. `今天各会员业绩是多少`
   - 修复前一次真实运行在 40 秒后被二次语义复核拒绝；运行标识：`085fcf9f6f3b4c53a51dcd8a0f6a7a9847259d8535f7fd46`。
   - 原因：来源注册表已经提供会员维度唯一默认指标，网关仍重复发起模型语义复核和修复，最终把合法默认绑定误判为不可靠。
   - 修复后用时 16 秒，返回 5 位会员、现金业绩合计 70,272 元及 5 行会员明细；运行标识：`1b390c93bce41593331f9a07f0374454c4e0c2ab196e9bdd`。
4. `今天各会员现金业绩是多少`
   - 用时 18 秒。
   - 返回 5 位会员、现金业绩合计 70,272 元及相同 5 行明细，确认明确指标路径不是依赖宽泛问法兜底。

上述用时是本机真人样本，不作为稳定性能承诺；本轮确认会员默认路径已从 5 次模型调用减少到 2 次模型调用，查询本身约 144 毫秒。

## 自动化与技术验证

- `breakdown-query.php`：30 项通过。
- `intent-understanding-contract.php`：144 项通过。
- `semantic-binding-guard.php`：12 项通过。
- `gateway-integration.php`：155 项通过。
- `gateway-review-regressions.php`：71 项通过。
- `metric-registry-contract.php`：89 项通过。
- `query-read-view.php`：64 项通过。
- 以上离线、夹具和契约检查合计 565 项通过。
- `query-mysql.php`：MySQL 5.7.44 真实查询 166 项通过；状态并发 8 进程、200 事务通过；管理发布并发 21 项通过。首次一次性容器未在就绪窗口内启动，重新建立容器后全量通过，未修改生产环境。
- 5 个修改的业务 PHP 文件语法检查通过；`git diff --check` 通过。

## 数据与权限边界

- 权威事实表、指标公式、金额分精度和后端数据权限均未改变。
- 门店合计在完整授权门店集合上计算后再分页；人员、会员等注册维度只有在 Reader 持有完整分组集时才返回精确合计，否则返回未知，不使用当前页推算。
- 只改只读查询与回答投影；无数据库迁移、无写事务、无远程数据变更。

## 本轮业务代码注释

- `AiGatewayServices.php`：说明来源注册表唯一默认绑定为何可以免除重复模型复核，以及适用边界。
- `AiAnswerRenderer.php`：说明证据透视、缺失值、零值过滤和对象中性结论职责。
- `MetricDefinitionRegistry.php`：说明会员维度默认指标的来源和明确指标优先原则。
- `MetricReadViewServices.php`：说明非零（含负数）优先只为防止有界页面丢失证据，不构成排名。
- `RegisteredMetricReadServices.php`：说明只有完整分组集才可提供精确合计和活跃对象数。

## 文件 SHA-256

- `tests/mohe-ai/breakdown-query.php`：`a57b02d5027c9c6ecd8ec45d128f67ac021894c013f551cbbb6bcec24fb544f8`
- `tests/mohe-ai/query-mysql.php`：`e48ec7b628bd0059a674a37f302b0e5879954715be1bba7c3d7ed4334498005e`
- `tests/mohe-ai/query-read-view.php`：`31d1507c92e39f079fce3483c9d78b2391904a9af638d8e7837543e7a4dfeab0`
- `AiGatewayServices.php`：`72831f8e7994094bd264ed7397cc6d374e7686464d266ae91fa2bfa0c438e123`
- `AiAnswerRenderer.php`：`f0c407f7ac7343990fe3faf1d552e024ee1206ad0161899e8fe9ba46f6689ee5`
- `MetricDefinitionRegistry.php`：`74411e375b319f50cb35b095c16736a35b9ceb471a243504fd6464689c6598a5`
- `MetricReadViewServices.php`：`b559ae06eeb99659ba2c7da5cc351ed51af4486a6fec11d86dea74cd8bd164d2`
- `RegisteredMetricReadServices.php`：`0a8805127a4cdb4aa854ae0d1d8e62d37d5466b320be65a52641fba3fb8031bb`
