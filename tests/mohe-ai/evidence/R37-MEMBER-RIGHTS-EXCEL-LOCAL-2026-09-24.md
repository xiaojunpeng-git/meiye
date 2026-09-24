# R37 会员权益 Excel 补齐：固定版本与本地复验

## 状态

- 用户确认将权益 Excel 纳入本轮，修完后整体发布；会员已获查看权限后，可读取该会员所有门店权益。
- 开发、自测完成；待产品经理对本记录固定版本确认验收。未为本轮新增修复 commit、部署或 push。
- 后续确认：2026-09-24 产品经理针对上轮固定版本交付回复“commit”，据此进入本地提交。本次未部署或 push；前述状态保留为交付时点记录。
- 当前工作区基线 `302f841c1a7b22cc786e0f3e65429b29f5899958`。其他任务在测试期间提交了 R32 预约闭环；本轮没有修改该闭环。
- 本记录补充并取代前一记录的“权益 Excel 未接通”限制，不改写前序失败的历史事实。

## 本次补齐

1. 回答读取权益时保存最小、精确到分的加密导出证据；不把上下文会员名单误当成权益。
2. 继续复用独立 Excel 任务、队列、写文件及下载通道；答案先完成，文件后台生成，失败不撤销答案。
3. 单人、多人权益均支持。汇总含余额、本金、赠送、有效卡数、剩余次数、剩余金额；卡项明细含会员、卡名、状态、有效期、次数与金额。
4. 同一句筛选并查看权益时，文件还包括同次查询的筛选依据。纯权益追问不添加未请求的筛选表。
5. 文件使用既有长表列契约，不另建报表引擎。屏幕整数元与文件分精度各遵循现有口径；不重新查库改变这次结果。
6. 文件绑定同时包含上下文结果与资产快照指纹。创建、执行及下载复核当前会员权限；保留会员关系校验，不能访问无权查看的会员。
7. 旧答案没有权益快照时仍拒绝导出，需重新提问；不会用旧名单伪造权益文件。

## 浏览器真实操作及文件核对

本地 `http://127.0.0.1:18081/admin/setting/mohe-ai`，真实模型、本地数据库、页面输入与点击。未做业务数据写入。

| 问题（均勾选同时生成 Excel） | 页面结果 | 问答用时 | 后台文件核对 |
| --- | --- | --- | --- |
| 这些会员的权益明细都给我看一下 | 3 位会员汇总和12条卡项，Excel已生成 | 18秒 | 42行精确回读通过 |
| 第一位会员的权益明细给我看一下 | 单会员及3条卡项，Excel已生成 | 13秒 | 12行精确回读通过 |
| 这个月现金业绩多少 | 现金业绩393310元，Excel已生成 | 23秒 | 普通指标路径成功，未被权益路径替换 |
| 最近30天到店恰好2次，而且实际收款销售额超过1万元的会员权益明细 | 筛选依据、3位会员汇总和12条卡项，Excel已生成 | 27秒 | 48行精确回读通过，其中6行筛选依据 |

实际任务：

- 多人：`uqe_34edd279fba1bfdd87363c321e52c40c`，SHA-256 `d7fe3792a2a41a978d75a0118e145e88bf48ccab9842d8489c56538ab767078b`。
- 单人：`uqe_273bfe3c56fea0f2ee341dab7b798ab4`，SHA-256 `8e4a452020fb9446fbb550bf2b95f090178763dfdd5025e0fa3379acd7f0f754`。
- 普通问数：`uqe_7132cf5fcb1a67148518e505474d6754`，任务 succeeded。
- 合并请求：`uqe_2651e9e18630ed9dd9b26b75a133e5f2`，SHA-256 `c58d31983e34b41143a33ce3bb52a921c0e93af09a48b8d9fc86a7d2d27397a8`。

多人文件经页面下载到本机 `Downloads/查询结果_20260924.xlsx`，文件校验值与后台一致。合并文件也点击了下载；本记录不将点击等同于第二份本地文件已落盘，其后台实体已完成逐格回读。复验读取真实 XLSX，校验表头、行数、每一格内容、禁止公式及精确金额。测试会话保留，导出开关已恢复关闭。

上述耗时是单次样本，不是P95指标；模型理解仍占主要等待，不宣称所有复杂问答均能在固定秒数内完成。

## 自动化、权限及边界

- member-analysis 19、member-detail-continuation 22、condition-set 80、skill-semantic-projection 19、intent-understanding-contract 144、gateway-review-regressions 71、gateway-integration 155，共510项通过。
- export-runtime-contract、export-source-contract（45项）、export-worker-contract通过。新增真实 XLSX 夹具验证分精度、资产/指标文件不可互换、撤销会员权限时先于查库拒绝；缺少或篡改快照、重复会员均拒绝。
- browser-independent-export-contract通过；mobile-ai-entry-transport 40项、browser-mobile-guidance 168项通过。后两项为契约/回调测试，不冒充微信真机验收。
- member-rights-live-readonly 5项通过：真实跨门店样本、合计一致、会员权限关闭拒绝、无关联门店拒绝。
- member-rights-export-live-readonly分别核对上述三个权益任务通过。
- PHP语法与 git diff --check 通过。
- 本轮未改前端、未上传小程序；未进行线上验收。复用手机端既有导出接口，不宣称微信真机下载已测。

## 数据及发布边界

沿用注册指标事实层筛选、后端权限、原签名结果集和权威会员资产投影；不新增业务写入、事务、迁移、回填或支付调用。多会员仍受既有100人完整结果集与运行预算限制，不把截断列表称为全部。导出保留最小证据、现有文件格数预算和有效期，敏感联系方式及内部会员引用不进入单元格。

共享 `CashierV3MemberDetailQueryServices.php` 存在其他任务未提交修改：本轮只纳入 `cards()` 移除门店过滤的获批块，不能夹带 `assertVisible()` 放宽块。获批块哈希仍为 `971320ab8389aecae95db1bb29e059072dfbc26bc064c842670fffd72de438a5`。发布前按精确块隔离并复核，其他任务脏改动不夹带。

## 文件分组与固定指纹

前端源码、数据库迁移、工程脚本：无。生成物是运行时 Excel，不纳入源码。证据为本文件及前序两份 R37 本地记录。新增/修改业务代码已补充职责、精度、来源绑定、权限及快照边界注释；原误用指标导出的路径已替换，没有另留一套权益队列。

下面文件相对 `美容源码/`；已跟踪范围补丁指纹 `9120364341a6568fe899f6ace48c7c8cd10a647171c44bceeb434381b0c5faa1`，新增文件按单文件哈希锁定。

| 后端源码 | SHA-256 |
| --- | --- |
| 后端代码/app/services/ai/AiGatewayServices.php | 03b4a05ee96c287e0c76813741ab9d28ddd3f9a15ef0e2b4f46accf103640954 |
| 后端代码/app/services/ai/context/MemberDetailContinuationResolver.php | 3b60d327010cf396612ee2aa00dce870b6ab2e56398dce8e3211228c3de40025 |
| 后端代码/app/services/ai/contract/AiIntentResultContract.php | 1176a3c0825b02078a09b01a6080b2a1d0f7fe1122d8c7068b6bc2a72aee2701 |
| 后端代码/app/services/ai/contract/AiIntentUnderstandingContract.php | 229bef98d34f34dba129f509d19038a166271e8328688905a216af2b0bfb9b86 |
| 后端代码/app/services/ai/execution/AiRunStore.php | 93a07af2a575b36fa7c141ad2314ac311a4fd355822d6f33db8912bd49b086c5 |
| 后端代码/app/services/ai/execution/AiExportRuntime.php | 2ef52033655be1020f9c9faca7d5bc734a702ee92018dd9b5cc1a23ee3086e47 |
| 后端代码/app/services/ai/presentation/AiMemberDetailAnswerRenderer.php | 5ee6ac321f2f5ad66226c7fce8b2295b7841e0aa8b13e3b0d10e79e669a9e913 |
| 后端代码/app/services/ai/presentation/AiMemberRightsExportProjection.php | 59f36250ead08103e06627ff65cfe8bb5906ab22dda70032570a83cf39cca382 |
| 后端代码/app/services/query/metric/MetricReadViewExportProvider.php | 0364430a321529b2ad376543c30b976a1b7fdaace5526c369058bb37b7f72985 |

| 自动化测试 | SHA-256 |
| --- | --- |
| tests/mohe-ai/condition-set.php | c62c8e83a4905e95f40a4389e1492b783e65317f8a3a389a9938f10201920426 |
| tests/mohe-ai/gateway-review-regressions.php | 65fff53b6f3df7313ebc3c065c06f693906bea6b07bbf823c0abfcaa098a0a60 |
| tests/mohe-ai/intent-understanding-contract.php | 6ff37f162eadfa81902106b61b1273f8696e08579923a7cf9efa970a6ebe4a29 |
| tests/mohe-ai/member-detail-continuation.php | 254971816117b6e9bc508651ca2f1dac88f1795427d2172c9b4e9dad5c971640 |
| tests/mohe-ai/export-runtime-contract.php | d8906dd3d318a15d4a2c4c825783a3e8baa4775c7eb05312fe67bb213b234aae |
| tests/mohe-ai/member-rights-live-readonly.php | 2b91f3b22c7d6e055743b46ca8d49ec822a320da864999ba01379ea2b0a07904 |
| tests/mohe-ai/member-rights-export-live-readonly.php | 82aa567bdb8bb02ae1e30443d0c7c75b1c33ed75378ac5fe4e803a050c0b0ec5 |
