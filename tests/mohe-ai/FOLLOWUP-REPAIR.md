# MOHE-AI-R8 人员追问继承修复 · 自测交接

基线 `073e524c`。本轮仅修改后端追问/回答与测试，不修改前端、数据库结构或业务事实，不部署远程、不自动提交。

## 原因与修复

旧 inheritContext 在验证证据后仍拒绝所有 business_filters/store_ids 非空的查询，导致人员首问完成而追问失败。新增 AiFollowupQueryPlanner 对已验证查询应用明确增量：日期、字典指标、形态、排行方向与人数；完整人员/门店筛选原样保留。不把聊天文字或历史答案当作权威条件，不把第一名改成固定五名。

入口仍校验上下文签名、账号/会话归属、期限和原证据；原一致读重放验证当前权限及人员绑定。新计划仍经过注册编译器、能力快照、当前权限与新的统一查询，生成新的证据/上下文；不是把原数字换个日期展示。权限/任职变化按既有约束拒绝。

无法识别的新对象、排除条件、未登记指标及模糊比较不静默删除，给出追问条件需确认提示，不计用户技术失败。当前新路径针对带筛选/门店条件的已完成查询；不宣称任意自然语言条件修改、跨失效引用或任意新场景已支持。人员回答在空结果时也明确评价指标。

## 数据架构自检

继续使用统一 MetricReadView、人员分配事实与指标字典；不新增 SQL/公式、不改事实粒度、成功时点、业务日期、冲销、当前任职解释与后端数据权限。原业务写路径、事务和 Outbox 无变化，无迁移或回填。新 Run 的幂等、取消与调用预算保留，查询/导出复用同一完整条件和证据。

## 自测证据

- 人员合成合同 43 项通过，新增日期继承、人员/指标/门店/排名保留、明确改指标/后3名、未知姓名/排除条件/不支持数量/模糊期间拒绝。
- host 与 PHP 7.4 完整回归退出 0；日志 `/tmp/mohe-followup-host.log`、`/tmp/mohe-followup-php74.log`。
- opt-in `analysis-local-live.php` 新增 `MOHE_ANALYSIS_TEST_FOLLOWUP=1`：在本地 UUID/库名校验后，真实 SiliconFlow 首问→明确人员范围→历史排行→追问本月→Excel；月度姓名/首名/整数金额及 Excel 分精度金额与独立聚合一致。标记 `LIVE_FOLLOWUP_MONTH_SCOPE_METRIC_RANK_AND_EXCEL_MATCH`、`PASS`。
- 18081 页面实际首问“今天做的最好的技师是谁”→选择美容师→劳动业绩→今日无事实并显示指标→“那本月做最好的呢”直接返回 2026-09-01 至 2026-09-09、美容师、劳动业绩、前1；没有再次澄清或遗漏人员筛选。
- 同一页面第三轮“那后3名呢”返回后3名，保留本月、美容师及劳动业绩。最终 host/PHP 7.4 全套回归退出 0，日志 `/tmp/mohe-followup-host-final.log`、`/tmp/mohe-followup-php74-final.log`。期间 browser-entry-contract 原断言在整段存储查找字符串 999，会误命中随机 ID/时间；改为检查迟到答案文本及卡片值，未放宽迟到发布保护。

固定源码/测试 SHA-256（基线 073e524c）：

~~~text
7bb5df4946d255a84f6ad25edb35a8307cf04eb4034692d1cbaf560f5e9f9711  后端代码/app/services/ai/AiGatewayServices.php
1df6c29dfbfd9474f550833b8002f838ae2e13a0342a0cc3ee943eb946e7874b  后端代码/app/services/ai/execution/AiFollowupQueryPlanner.php
bc83af2881d4c658946f7df7054427dd337e3986e264a0322ba1d495d3c61c0d  后端代码/app/services/ai/execution/AiRunStore.php
52ba746458188cdaf5cf84527f910445e04e303de129fe6949d33e90d81f2086  tests/mohe-ai/personnel-analysis.php
0a89520fc3538ad4a0838e8cfb568678adbcbc26c207433da67c4a8128d50400  tests/mohe-ai/analysis-local-live.php
f118412128925bc4b6ab574a2566b1c0717b604c35bea7816ce4e9de0d22ed24  tests/mohe-ai/browser-entry-contract.mjs
~~~

## 文件分组

- 后端：AiGatewayServices.php、execution/AiFollowupQueryPlanner.php、execution/AiRunStore.php。
- 自动化测试：personnel-analysis.php、analysis-local-live.php、browser-entry-contract.mjs。
- 证据：本文件及本地日志。前端/迁移/工程脚本/正式构建产物：无。
- 产品经理固定版本验收待确认；无 commit、push 或生产发布。
