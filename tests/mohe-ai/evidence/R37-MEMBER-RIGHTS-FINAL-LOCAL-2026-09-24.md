# R37 会员条件筛选、连续追问与批量权益：本地复验

## 状态与边界

- 基线 HEAD：`3ecdbe47aff4060f2dce59d0e32dd62351cbc6cc`。
- 本次新增修复尚未由产品经理对固定版本验收；未 commit、未部署瑞昊、未 push。
- 用户要求先修完再整体发布，本记录不等于上线完成证明。
- 2026-09-24 产品经理明确：有权查看该会员后，可以查看该会员所有门店的权益。会员本身的权限校验不放宽。
- 前序失败记录保留在 `R37-MEMBER-DETAIL-RETEST-2026-09-24.md`，未改写为成功。

## 修复内容

1. 复合条件不能被一个命中的注册指标覆盖；保留所有条件及证据。
2. 已识别的同一注册指标别名不重复追加绑定要求。
3. 一个明确时间范围的格式错误由共享日期解析器校正；不猜测比较两侧，不改业务指标。
4. 当前完整条件替换继承条件时，按已校验的结构处理，不把自然语言词与指标代码直接比较。
5. “这些会员的权益”按原已验证结果集读取；“这个会员”面对多人仍要求明确序号，不私选第一人。
6. 同一问题内的条件与详情分别保留：先完成授权筛选，再读取对应会员权益。结果包含筛选依据，不能只返回名单。
7. 多人结果复用两张表（汇总、卡项明细）而非每位会员创建一套页面；仍沿用两端已有的 sections 契约。
8. 每个会员读取前检查当前权限和任务预算；不允许把截断名单称为全部会员。
9. 发现并阻断权益 Excel 错配：既有指标导出器不能拿上下文名单冒充权益明细。新增权益投影的 Excel 尚未接通，明确失败而不伪报成功。

## 浏览器真实操作

目标：`http://127.0.0.1:18081/admin/setting/mohe-ai`，实际模型与本地数据库；通过页面输入和点击操作，不以接口夹具替代。测试会话留在浏览器，Excel 开关已恢复关闭。

| 用例 | 结果 | 页面耗时 |
| --- | --- | --- |
| 最近30天到店2次，消费1万元以上的会员详情 | 修复中曾仍仅返回名单，未算通过；补齐模型语义契约后返回筛选依据与3位会员权益汇总 | 最终34秒 |
| 最近30天到店恰好2次，而且实际收款销售额超过1万元的会员详情 | 返回3位会员权益；不再丢弃详情请求 | 28秒；后续带Excel复验32秒 |
| 这些会员的权益明细都给我看一下 | 3位会员汇总及12条卡项明细；分组正确 | 18秒 |
| 最近30天到店恰好999次，而且实际收款销售额超过1万元的会员详情 | 返回0人，不读取或编造权益 | 22秒 |
| 勾选Excel后追问这些会员的权益明细 | 回答正常显示；导出错配修复后显示“Excel 暂无法生成，问答结果不受影响。” | 16秒 |

原始口语问法在已有“实际收款销售额”上下文中测试；不把这条成功解释为所有无上下文的“消费”问法都已消歧。

批量样本权益：三位会员有效卡项3/6/3张，剩余项目22/94/165次，剩余金额26800/29452/7585元。页面为整数元，数据读取和对账保留分精度。

## 性能证据

自然语言成功样本35秒的服务端分段：会员脱敏候选读取4264ms、问题理解13577ms、绑定11540ms、绑定复核1441ms、查数1124ms。纠错重试已消除，但模型理解和绑定仍是主要等待来源。

本轮复验为单次功能样本，不冒充P95性能测试，不承诺所有复杂问答低于某个秒数。实际问答观察为16—34秒。

## 自动化与真实只读核对

- member-analysis：19；member-detail-continuation：13；condition-set：80；skill-semantic-projection：19；intent-understanding-contract：144；gateway-review-regressions：71；gateway-integration：155。共501项断言通过。
- export-runtime-contract、export-source-contract、export-worker-contract通过，包括真实XLSX读写夹具；这些不等于会员权益Excel已支持。
- `member-rights-live-readonly.php`：5项真实数据库只读核对通过：跨门店权益不随操作门店缩小；金额/次数明细合计一致；关闭会员权限拒绝；无门店关联拒绝；存在真实跨门店样本。
- `git diff --check` 通过。未做生产库写入、迁移、支付或权益变更。
- 本轮浏览器验收是平台端；没有声称微信小程序真机验收完成。

## 数据架构自检

筛选复用注册指标、统一事实粒度和后端门店权限；不复制计算SQL。权益复用会员详情权威只读投影，先校验会员关系，再读取跨门店权益。既有退款、有效期、卡项状态口径未重写。结果集引用保留签名查询及有效期，不使用模型姓名或客户端顺序查库。无业务事务、迁移、历史回填或幂等写入变更；批量读取受现有名单上限和运行预算限制。

## 文件分组及发布隔离

- 后端：`app/services/ai/` 下 Gateway、MemberDetailContinuationResolver、AiIntentResultContract、AiIntentUnderstandingContract、AiRunStore、AiExportRuntime、AiMemberDetailAnswerRenderer。
- 自动化测试：本记录哈希表中的5个测试文件。
- 前端源码、数据库迁移、工程脚本、生成物：本轮无新增。
- 证据：本文件与前序复验记录。
- 共享依赖 `CashierV3MemberDetailQueryServices.php` 当前含其他任务未提交改动。只允许本轮纳入产品经理确认的 `cards()` 跨门店权益过滤移除块；不得夹带 `assertVisible()` 放宽块。后者不属于本轮发布范围，原严格会员关系校验保留。
- 授权依赖块（`@@ -644`起至文件diff末尾）SHA-256：`971320ab8389aecae95db1bb29e059072dfbc26bc064c842670fffd72de438a5`。发布前必须重新核对该块及严格成员校验组合。
- 其余脏工作区（收银、预约、移动登录、配置、迁移等）保持原状，不打包夹带。

## 固定源码指纹

以下路径相对 `美容源码/`。已跟踪本轮AI与测试diff SHA-256：`fcf264e6a46e77afe8b427d587a203512bfe223ec602b05b53fe30e9e43bf35f`；未跟踪只读测试另按单文件哈希锁定。

| 文件 | SHA-256 |
| --- | --- |
| 后端代码/app/services/ai/AiGatewayServices.php | f90bc3ed76bc5731ccb17a9dcbb2d9c8b93d49199c21cc0520b83386aa9687ff |
| 后端代码/app/services/ai/context/MemberDetailContinuationResolver.php | 3b60d327010cf396612ee2aa00dce870b6ab2e56398dce8e3211228c3de40025 |
| 后端代码/app/services/ai/contract/AiIntentResultContract.php | 1176a3c0825b02078a09b01a6080b2a1d0f7fe1122d8c7068b6bc2a72aee2701 |
| 后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php | 229bef98d34f34dba129f509d19038a166271e8328688905a216af2b0bfb9b86 |
| 后端代码/app/services/ai/execution/AiRunStore.php | 93a07af2a575b36fa7c141ad2314ac311a4fd355822d6f33db8912bd49b086c5 |
| 后端代码/app/services/ai/execution/AiExportRuntime.php | 8801f0da6f6a2f5790ef0ec6106bde775e85cbb4e9dcd674d894b40fcfc10ba5 |
| 后端代码/app/services/ai/presentation/AiMemberDetailAnswerRenderer.php | 5ee6ac321f2f5ad66226c7fce8b2295b7841e0aa8b13e3b0d10e79e669a9e913 |
| tests/mohe-ai/condition-set.php | c62c8e83a4905e95f40a4389e1492b783e65317f8a3a389a9938f10201920426 |
| tests/mohe-ai/gateway-review-regressions.php | 65fff53b6f3df7313ebc3c065c06f693906bea6b07bbf823c0abfcaa098a0a60 |
| tests/mohe-ai/intent-understanding-contract.php | 6ff37f162eadfa81902106b61b1273f8696e08579923a7cf9efa970a6ebe4a29 |
| tests/mohe-ai/member-detail-continuation.php | e63e8e5830ee5671ae34238680a1badf5eebbd0def5b6ea266bbd920a7a9298f |
| tests/mohe-ai/member-rights-live-readonly.php | 2b91f3b22c7d6e055743b46ca8d49ec822a320da864999ba01379ea2b0a07904 |
