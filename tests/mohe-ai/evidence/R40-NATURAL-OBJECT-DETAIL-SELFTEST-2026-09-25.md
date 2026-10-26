# R40 魔核 AI 自然语言对象追问自测记录

## 验收范围

- 将“详情呢、具体看看、展开说说、他怎么样”等自然语言追问统一为模型理解的 `object_detail` 语义，不在 PHP 中匹配整句话或关键词。
- 服务端只从上一轮已签名、已复核的结果行解析稳定对象身份；不使用模型生成的姓名、门店名或编号查询。
- 人员、门店、会员共用对象追问入口：人员复用所问指标与期间查询选中人员；门店展示一行带门店名的明细；会员继续复用既有权益/概览读取。
- 纯对象追问在理解完成后直接进入统一查询，不再调用指标绑定模型与修复模型。

## 自动化回归

2026-09-25 本机执行：

- `intent-understanding-contract.php`：144 checks PASS
- `semantic-binding-guard.php`：12 checks PASS
- `skill-semantic-projection.php`：19 checks PASS
- `semantic-guidance.php`：97 checks PASS
- `gateway-review-regressions.php`：76 checks PASS
- `registry-execution.php`：103 checks PASS
- `result-reference.php`：4 paths PASS
- `member-detail-continuation.php`：22 checks PASS
- `object-detail-continuation.php`：18 checks PASS
- `git diff --check`：PASS

## 真人浏览器验收

环境：`http://127.0.0.1:18081/admin/setting/mohe-ai`，平台管理员账号，真实本地统一查询数据。

1. 新对话提问“这个月业绩最高的人员是”，结果为姚慧兰、销售人业绩 78,122 元。
2. 继续提问“详情呢”，9 秒完成，返回销售人业绩 78,122 元、统计时间 2026-09-01 至 2026-09-25、人员范围姚慧兰。
3. 新对话提问“这个月销售额最高的门店是”，结果为合肥世纪中心店、销售额 170,421 元。
4. 继续使用不含“详情”二字的“展开看看”，5 秒完成，返回合肥世纪中心店单行销售额明细与同一统计期间。
5. 中途发现默认门店排行没有显式 `object_kind`，服务端首次正确识别语义但拒绝对象能力；补齐注册表默认门店粒度后重新执行第 3、4 步通过。

最终门店追问 Run：`4e881d6ab8bc2d46eb42ac0db489cd2c043c04567e199c9b`

- 状态：COMPLETED
- 模型调用：1 次（understand_meaning 4,265 ms）
- 统一查询：99 ms
- 诊断：`verified_object_detail_continuation_admitted`

人员追问 Run：`fa7511d1d30d79f5116e73d971ab0e738c48561969d6e7f3`

- 状态：COMPLETED
- 模型调用：1 次（understand_meaning 6,915 ms）
- 统一查询：238 ms
- 诊断：`verified_object_detail_continuation_admitted`

## 数据与权限边界

- 权威数据仍由统一指标注册表、统一事实读取和后端权限快照提供；本次未新增 SQL、迁移或另一套统计逻辑。
- 对象身份来自上一轮冻结结果，人员使用 `person:<id>`，门店使用已授权 `store_id`，会员继续使用 `member:<id>`。
- 未编号的单数追问只允许上一轮结果唯一；多对象时返回要求说明第几位，不默认选择第一行。
- 门店详情使用选中门店的一行 breakdown，确保名称和数值同时可见；人员详情使用选中人员 summary，并保留人员范围说明。

## 注释与源码治理

本轮新增或更新职责/边界注释的文件：

- `后端代码/app/services/ai/context/ObjectDetailContinuationResolver.php`
- `后端代码/app/services/ai/AiGatewayServices.php`
- `后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php`
- `后端代码/app/services/ai/execution/AiRunStore.php`

未保留人员/门店两套平行句式判断；旧 `member_detail` 仅作为已存储契约兼容输入，新模型只输出统一 `object_detail`。

## 提交后复测与修复（2026-09-25）

基线：`1dbf2837`。本节是该提交后的追加修复，不改写上面的历史测试结果。
验收状态：Codex 本地自测完成后，产品经理于2026-09-25回复“commit”，确认本次交付并要求本地提交。该确认不包含部署或 push；实际提交号以包含本记录的 Git commit 为准。

### 实际发现并修复

1. 门店第一次“展开看看”成功后，再问“再展开看看”被拒绝。旧解析只接受排行，不能继续解析已转换为概览的可信结果。现在支持已选定人员的 summary，以及明确限定一家门店的 breakdown；不扩大原指标、时间或权限。
2. 真实模型有时把“详情呢/具体看看”表达成 `target=set`。当可信结果仅有一个对象时，集合和单对象语义等价；原实现仍拒绝。现在允许可信单元素集合，多个对象必须明确选择，不会默认取第一项。
3. 多人结果下上述 set 语义原本被提示成“不支持”。现返回对象选择提示，用户指定第二位后可继续。
4. `gateway-components.php` 的过时断言还要求把“最高”直接确定为排行；按既有语义边界改为验证：保留前五数量证据，但不由词表虚构完整操作。未改变生产解析器，也没有恢复旧硬映射。
5. 追加仅含枚举的诊断（single/set、summary/rights、来源形态），不记录问句、姓名、ID 或经营数字，以便区分理解结果与执行能力边界。

### 真实浏览器路径与运行证据

入口：本地 `18081`，真实登录账号、真实模型、现有本地数据；通过页面输入并点击发送，非模拟响应。

| 场景 | 页面结果 | Run / 关键耗时 |
|---|---|---|
| 前两位人员排行后，问“第二位的情况具体看看” | 刘雅婷，销售人业绩 71,402 元；9月1日至25日；7秒 | `44c1fbc8e209bcee3778aed8ce96086bccd334853df1c701`，理解4294ms，查询225ms |
| 上述人员概览后继续“具体看看” | 仍为同一人、同一金额、同一期间；6秒 | `790e2a7dd66bf8cf26f6a30eb77e5174c9ff0b4b7b67b522`，理解4214ms，查询263ms |
| 门店排行后“展开看看” | 合肥世纪中心店，销售额170,421元；7秒 | `28d1bb352e39eb31ae4f315ea9515e1f1722cc616a499cc9`，理解6269ms，查询109ms |
| 门店概览后“再展开看看” | 同一门店、金额和期间；7秒 | `064123ec04c4205a6aec52cd3c3d790afaedeee08f6ba8a9`，理解5371ms，查询75ms |
| 会员排行后“这位的权益给我看看” | 返回相同会员的权益概览及13张有效卡项；7秒 | `1d7c9b5808250df823185cbf718af119205a631949a13bd8`，理解5242ms，权益读取369ms |
| 前两位人员排行后“详情呢” | 提示指定第几位，未擅自选择；6秒 | `97c9118575c020e6cb707eda9de7790719dd3da3ea94996a`，理解4784ms，无数据查询 |
| 收到选择提示后继续“第二位具体看看” | 正确返回刘雅婷、71,402元，日期不变；8秒 | `e9452f29995d8244ef5de5fae90946df556a532cc8f9cc47`，理解5798ms，查询265ms |

以上成功追问均只调用1次模型，未进入指标绑定/绑定修复。多人歧义在底层 Run 中以 `FAILED / AI_OBJECT_DETAIL_SELECTION_REQUIRED` 结束，但归类为 neutral 业务边界，不能当作查询成功。

性能边界：本次新门店排行19秒，前两位人员排行25～26秒；本轮优化的是追问路径，不能宣称所有问题已达到6～7秒。

### 最终自动化回归

- 原记录的9组测试重新执行通过；其中 `object-detail-continuation.php` 从18项扩展至31项。
- `gateway-components.php`：139项通过。
- `query-read-view.php`：64项通过（含可信视图和权限边界，隔离夹具）。
- `personnel-analysis.php`：56项通过（合成数据）。
- `gateway-integration.php`：155项通过（SQLite、模型及事实夹具）。
- `state-store-contract.php`：51项通过（隔离SQLite）。
- 宿主PHP及本地执行容器对2个修改后端文件语法检查通过；`git diff --check`通过。
- 新增边界覆盖：单元素集合、连续追问、多人集合、错误门店ID、截断结果、人员群体冒充单人、序号越界；旧测试期待被替换，不保留相互矛盾的并行断言。

### 交付范围与限制

- 后端源码：`AiGatewayServices.php`、`context/ObjectDetailContinuationResolver.php`，均补充职责/权限边界注释。
- 自动化测试：`object-detail-continuation.php`、`gateway-components.php`。
- 验收证据：本文件。
- 前端、数据库迁移、工程脚本、构建生成物：本次无变更。
- 数据架构：不新增事实或SQL，不写业务数据、不改金额算法；仍复用签名结果、统一查询与当前权限复核。仅重启本地AI执行进程加载源码，其他实例未操作；线上版本、迁移、生产备份不适用。
- 当前人员/门店的“详情”是原指标及期间的单对象概览，不是逐笔订单、服务或业绩分配明细；会员权益继续使用已有明细服务。未接入的对象类型及多对象明细不冒充已支持。
- 小程序本轮未进行真机复测；不能以本地平台浏览器通过代替小程序验收。

### 固定修复版本指纹

基线 HEAD `1dbf2837`；以下4个源码/测试文件补丁 SHA-256：`dad533c2d9735d70386f7cca8a12d8adae4e2c5e2727221a27ef67bf406b3ef7`。

| 文件 | SHA-256 |
|---|---|
| `后端代码/app/services/ai/AiGatewayServices.php` | `590d21723fd1f08dc8d36a2bfb56ed1422dc743ef327c5448d543aec18850244` |
| `后端代码/app/services/ai/context/ObjectDetailContinuationResolver.php` | `66ad8bb0d6e79287fe530a84da3f0ca72f227533332915a994e9b56609472c91` |
| `tests/mohe-ai/object-detail-continuation.php` | `cafcba6af3a5658feeb85545806443a74115d8a74b2d6f6d9bc279e22d5bcf91` |
| `tests/mohe-ai/gateway-components.php` | `3c9cc2494aa3062b9a85220afd6aafbcf1c28b2c00cc92071e7bea8faa232eb4` |
