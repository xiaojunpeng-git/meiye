# R50 确认卡与暂停续查补修 — 2026-09-27

## 范围与状态

用户重新打开R50，要求修复确认提示藏在底部、等待确认仍显示思考，并参考白底圆角问题卡样式。当前开发与针对性自测完成，待产品经理确认；未提交、部署、push或上传新体验版。没有改变历史数据覆盖起点，也没有修改其他业务代码。

## 原因及实现

- 小程序的420px阅读占位原来位于确认卡之前，造成用户需穿过空白才看到操作。确认卡移至当前问题后，等待确认时不渲染该占位；进入新步骤定位到卡片。
- 等待用户选择时暂停客户端计时并隐藏“正在思考”；确认后重新开始本次执行段计时。恢复会话、第二次确认和提交失败同样处理。
- 白色圆角卡片、编号选项、浅灰选中态、黑色确认按钮；取消查询复用已有取消接口。选项值、日期限制、幂等提交、权限和修改已确认条件沿用原契约。未照搬参考图内的Git业务内容，也没有添加语音、跳过或自由回复能力。
- 后端异步工作队列误用暂停前deadline筛掉合法确认续查。改为等待状态使用原有累计10分钟确认有效期；恢复执行仍只使用剩余预算，不重新获得完整预算。迟到确认读取原任务终态，不将已结束任务重新排队。

## 验证

- browser-mobile-guidance-contract：178项通过，含两段等待计时、卡片位置、恢复和续查；另R45展示检查通过。
- browser-guidance-contract：160项；browser-workflow-contract：34项；browser-turn-grouping-contract：2项；browser-lifecycle-race-contract：3项；browser-entry-contract通过。旧placeholder断言按R46已确认的空输入框更新，不恢复默认提示。
- async-execution-state-contract通过：受控时钟跨4分钟、恢复队列、保持剩余预算、累计10分钟过期及过期迟到提交。
- runtime-contract：105项通过；PHP语法、git diff --check通过。
- 本地18081来源仍为admin正本，共享入口重编译成功。HBuilderX小程序编译成功；存在既有span选择器警告及touristappid自动打开警告，没有修改其他任务的manifest。
- 电脑端实际输入原句，卡片与日期选项在可见区；选择后2秒返回合肥世纪中心店133,422元，统计时间2026-08-10至2026-09-27。仅为本地样本，不是线上业务金额对账或全量性能承诺。见desktop-clarification-card.png及desktop-clarification-result.png。
- 曾实际保留旧卡超过10分钟，首次点击显示通用请求错误；随后读取终态显示“本次选择等待已超时，请重新提问”。据此补充迟到确认返回终态代码及自动测试；最后这一分支尚未再次进行10分钟UI等待，不宣称全路径人工通过。
- 小程序开发者工具保持瑞昊线上，实际输入原句后新卡片可见、等待计时停止，选择并确认已进入继续查询。见mini-clarification-card.png。随后热编译回到登录页，未保存该次最终结果，因此不将其记为本补修完整续查验收，更不等同实体手机通过。线上尚无本次后端等待修复。

## 文件分组与注释

前端源码：shared/mohe-ai/browser-entry.mjs、mobile-vue3/src/shared/components/mohe-ai-entry.uvue。后端源码：app/services/ai/execution/AiRunStore.php。三处均补充职责与限制注释。

自动化：async-execution-state-contract.php、browser-mobile-guidance-contract.mjs、browser-guidance-contract.mjs、browser-entry-contract.mjs。用例索引：MOHE-AI-ROUND22-29-TEST-CASES.md。数据库迁移和工程脚本：无。生成物：仅本地admin开发编译、小程序unpackage派生物；不作为源码修改。验收证据：本记录、三张截图、根目录design-qa.md增量。历史测试证据不删除。

## 首次补修固定版本（历史指纹，已被下述补充修订替代）

基线HEAD：c54d0849。以下7文件补丁SHA-256：`c858b4a6b206c1f3868a50653180f51990d629ca617de714eb97b42aef2255d8`。

| 文件 | SHA-256 |
| --- | --- |
| mobile-vue3/src/shared/components/mohe-ai-entry.uvue | 71cec38d706d37133f4d81983f667a861f59df3996907b826e516a07d7a1a6e3 |
| shared/mohe-ai/browser-entry.mjs | 995ce1f77a295acd96bf23baf8e9733033f1c52efdaad6adda9f7143d748d927 |
| app/services/ai/execution/AiRunStore.php | 86590bd41a670af482419a3206886f088ae671a272da29804e030325b40f9cab |
| async-execution-state-contract.php | 2e6196452d1d446418890cc49e134d397373e28cca8a7cf5fba012f29a1276b4 |
| browser-mobile-guidance-contract.mjs | 96f0d0d7841d892d1e28410c05b28fbf0ea676452fe52b0bbf518eec751cf622 |
| browser-guidance-contract.mjs | 0cf4d31658c69f1869bf7cc1f9223409fd4da6b3c1096403e904a3e6d0afd191 |
| browser-entry-contract.mjs | 51034a4e43469e2e6d12db356cb4921271b763b0269a6ea3c650aae67a60771b |

工作区仍有其他任务改动，均未暂存或覆盖；后续仅按魔核AI文件清单提交。新样式需更新小程序包，后端等待修复需单独部署后才能在瑞昊验证。

## R50 补充口径与续测（2026-09-27，未发布）

产品经理追加确认：统计条件严格使用用户指定起止日，只读取区间内已有数据；不因部分历史数据未接入要求裁剪确认，也不在结果旁解释历史数据缺失。此口径覆盖上文测试所用旧的历史覆盖边界行为，旧记录与截图仍保留。

### 实现与清理

- 编译器、统一读取视图、日期投影与引导共用同一日期规则；删除历史接入日起点拦截及无调用的coverageStart参数，不为原句新增写死分支。查询/比较保持原区间。
- 日期合法性、未来日期、现有366天单次跨度限制、后端权限及事实金额口径未放宽。超过跨度仍需明确选择，不自动裁剪。
- 无事实记录的门店/日期排行不再用补零行生成虚假的第一名；有真实零值记录的既有排名口径保留。无数据使用既有“所选时段暂无符合条件的…记录”提示。
- 电脑端确认接口直接返回终态时，不再覆盖为“已接纳选择，正在继续查询”，也不重复启动轮询。旧Run的历史错误解释和分类保留，以便恢复旧会话；新路径不再产生历史覆盖错误。

### 实际交互证据

- 电脑端本地，原句“2026年3月到今天，最高营业额是哪个门店”：1秒，统计期间2026-03-01至2026-09-27，直接返回结果，无历史接入提示。见desktop-full-requested-range.png。
- 同一对话问2026-03-01至2026-03-31：1秒，显示暂无符合条件的现金业绩记录，日期未替换。见desktop-empty-requested-range.png。
- 最新固定代码下问2025年3月到今天，触发现有366天跨度确认；明确选择2025-09-27至2026-09-27后2秒返回，卡片及等待状态正常收起。见desktop-long-range-confirmed.png。该测试不代表超366天原区间已执行。
- 曾在本地admin重编译期间发起一次相同问题，页面显示3分20秒；保留该Run，不计作正常服务性能样本。编译结束重新加载后的独立重测为上述1秒。
- 电脑端原确认卡等待超过3分钟后继续，1秒返回；刷新可恢复待确认卡。见desktop-wait-over-3min-result.png。超过10分钟再提交能得到明确过期结果，但发现页脚仍错误显示继续查询；本次已补终态守卫，DOM即时完成回归通过。
- 小程序保持瑞昊线上：新卡紧接问题显示，无思考状态；不选直接确认时卡内提示“请完成当前选择或填写有效日期”。之前及时选择的续查结果在重新登录后确认已返回，显示5秒及2026-08-10至2026-09-27（旧规则），不能记为新完整日期规则验收。
- 小程序另一次保留卡片超过确认有效期后，结束并显示“本次选择等待已超时，请重新提问”，输入框恢复，无持续停止/思考状态；见mini-wait-expired.png。
- 小程序新后端规则尚未部署，未切本地，也没有新增实体手机验证。本补修不能标为双端完整业务验收通过。

### 自动化与真实读取

date-policy 56；date-range-guidance 15；r50-calendar-range 32；intent-understanding-contract 154；query-read-view 64；registry-execution 103；temporal-ranking 5；gateway-review-regressions 85；cash-report-projection 29；runtime-contract 105；browser-guidance 163；browser-mobile-guidance 178及R45组；browser-entry与async-execution-state均通过。PHP语法和diff检查通过。

r50-calendar-local在本地容器真实读取通过：原区间03-01至09-27、现金业绩口径、月份排行、无记录期间；不改业务数据，仅创建既有机制的短期只读视图。测试首次空区间检查揭示零值伪排名，修复后重测通过。query-read-view及registry-execution旧覆盖拒绝断言已改为验证原日期保留，没有删除权限、重放和跨度断言。

### 文件分类与边界

前端源码仍为上述2文件；后端源码新增AiGatewayServices、AiDateRangeGuidancePlanner、AiRegisteredPlanCompiler、MetricGroupedProjection、MetricReadViewServices、MetricQueryDatePolicy，加AiRunStore共7文件，修改处均有职责/边界注释。新增调整的自动化为date-policy、date-range-guidance、intent-understanding-contract、query-read-view、registry-execution、r50-calendar-local；用例在B07、A02、G04/G05更新。无迁移、无历史事实回填、无生产业务数据修改。历史记录与其他任务脏文件保持不动。状态：本地开发与自测完成；尚待产品经理确认固定版本及瑞昊发布授权，再完成小程序新规则验证。


### 最新固定版本（替代上方首次补修指纹）

产品经理随后回复“确认”，已验收以下固定版本并授权仅部署本轮魔核AI至瑞昊、随后测试连接线上的小程序。本次不包含push或上传体验版授权；线上验证状态另记发布记录。

基线HEAD：`c54d0849`；19个源码/测试文件补丁SHA-256：`98f2d0fa18ed826bb31a26d378b82be78ad96411c40672ce013a4dd275640e65`。产品经理确认须对应本版本；尚未提交、部署或push。

```text
da7d15c26e44fa7c8f8312833020782a3e8708a88ce387b830446b3334c0dacf  前端代码/shared/mohe-ai/browser-entry.mjs
71cec38d706d37133f4d81983f667a861f59df3996907b826e516a07d7a1a6e3  前端代码/mobile-vue3/src/shared/components/mohe-ai-entry.uvue
89214bfce18f89244265d0465146f09c3871abe3c405d7b607410ed71eebccb1  后端代码/app/services/ai/AiGatewayServices.php
12c474bfba53546896519bcaff8ae36445b988a50baa79b74ac7013634f4ec6a  后端代码/app/services/ai/execution/AiDateRangeGuidancePlanner.php
1c0a52b1eb8c966dd3d7f547fd5962d06126480aff8480a7761dc2069ef7d776  后端代码/app/services/ai/execution/AiRegisteredPlanCompiler.php
86590bd41a670af482419a3206886f088ae671a272da29804e030325b40f9cab  后端代码/app/services/ai/execution/AiRunStore.php
f9e27e0711ef05efa86698a7d6a91860836e269770c2ad513b9d0e95c7b97779  后端代码/app/services/query/metric/MetricGroupedProjection.php
cbb245d97600c520662cc083c63c39326df7116a88cd501d47f61ef6c76635b7  后端代码/app/services/query/metric/MetricReadViewServices.php
a753a7cb510377e4db71e1a3abc1396ee15534cddbb69ec2b6b651e110e30263  后端代码/app/services/query/metric/MetricQueryDatePolicy.php
2e6196452d1d446418890cc49e134d397373e28cca8a7cf5fba012f29a1276b4  tests/mohe-ai/async-execution-state-contract.php
51034a4e43469e2e6d12db356cb4921271b763b0269a6ea3c650aae67a60771b  tests/mohe-ai/browser-entry-contract.mjs
b458f9ca224128045e94b3bce8fc73a6b149b42e192d78dd437285eb62c53ba1  tests/mohe-ai/browser-guidance-contract.mjs
96f0d0d7841d892d1e28410c05b28fbf0ea676452fe52b0bbf518eec751cf622  tests/mohe-ai/browser-mobile-guidance-contract.mjs
85aef49703ec0af449d507cd52be054ea3a0902403038d46818a76764ff78c5a  tests/mohe-ai/date-policy.php
e69f7cfebe82ff66a2ac189a81c8fb2e3080f33f2b768f8754f7093be944a145  tests/mohe-ai/date-range-guidance.php
865b18dd6d4d23fbd8b36e36f8e31444db115a0536ac8932b2f06ddc9e2513ee  tests/mohe-ai/intent-understanding-contract.php
52d0070143bbfc2f36f00a924f496ce11dc4ab176222ec922ad4cf25c833823d  tests/mohe-ai/query-read-view.php
985d6dc7c60a8eafcc642607568a75535a1bf58ef737b374f8a8ac1ad11c965b  tests/mohe-ai/registry-execution.php
06f3a51f2c68e20d8d13f3877b4758c6eede9fbc01bfa69d788450c9d08a75aa  tests/mohe-ai/r50-calendar-local.php
```
