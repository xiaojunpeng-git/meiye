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
