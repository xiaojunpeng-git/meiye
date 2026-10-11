# R8 项目替换目标权益欠款口径：本地自测与待验收版本

- 基线 HEAD：`dac7aed256a477d375a77726fe71c67c6b5c9e79`
- 状态：开发与自测完成，待产品经理对本固定版本确认验收；未提交、未部署、未推送。
- 范围：项目替换从已可用次数产生的目标权益，不重复承接原卡欠款。原欠款仍留在来源权益；目标自身明示欠款仍限制使用。普通购卡、项目升级和伪造来源标记不适用豁免。
- 权威数据：`cashier_v3_card_operation` 的成功项目替换、原订单及已落库项目明细。只改读取及核销限制口径，不新建财务事件、不改订单金额或库存、不迁移数据。项目替换来源在既有事务内按欠款限制校验并扣减。

## 闭环文件

| 类别 | 文件 | SHA-256 |
| --- | --- | --- |
| 后端源码 | `后端代码/app/services/order/store/WriteOffOrderServices.php` | `adefe5dcf857c91837bf3db7fbb7ae3922e05acfa27e42b4083c36999f601740` |
| 后端源码 | `后端代码/app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php` | `fae2060b143f9f4e2f213c9a10e0f720d526edc3b5367f8767a20cef6ebdc8e3` |
| 后端源码 | `后端代码/app/services/cashier/v3/card/CashierV3ProjectReplacementDebtPolicy.php` | `ba43afa98badda8b540e3b20ce7dda43c5a2be15fd2cb7effed7dd8a0dd4106d` |
| 自动化测试 | `tests/cashier-v3/php/r8-project-replacement-debt-mysql.php` | `05444a5da727854de05c0bb7f37abe90a2772bad3d8e24ee3e55483f9ab34caa` |

已跟踪源码补丁 SHA-256：`e009bb3b505afb2ca46d6ea0e45c0b1dafbb7fb5988a067011490555ca9551b7`。本表包含新文件哈希；其余脏工作区改动不在闭环内。前端源码、数据库迁移、工程脚本、生成物：无。业务注释补充于两处债务计算分支、共享卡限制分支及新策略类，说明只限已持久化替换权益及不得二次计债的边界。

## 自测

1. 本地 `ruihao` 数据，成功替换单 `CK26101100001`：来源订单待还 ¥38，目标“舒缓霜”1次。只读事务测试 9/9 通过：目标不分摊原欠款、来源行保留全额欠款、目标可用1次、目标自身欠款仍受限、升级与无效操作标记不豁免；数据库写入0。
2. 本地收银页面重新选择韩娇娇、打开“使用权益”：原卡仍显示欠款38，目标“舒缓霜”在可用列表显示1次，`添加`按钮可用。未添加、结账或改动业务数据。
3. PHP 7.4 对三处后端源码及测试文件语法检查通过；本闭环已跟踪文件 `git diff --check` 通过。
4. 既有 `r14-shared-card-debt-limit-mysql.php` 前12项通过，在“权益选择器升级预览金额与最终单卡抵扣完全一致”断言失败；其选中订单 `2318108` 只有普通购卡明细，无项目替换目标，故不属于本次新增判定路径。该旧用例整体未通过，需单独排查，不能记作回归通过。`r50-entitlement-cancelled-debt-mysql.php` 缺少历史卡夹具，未完成验证。

## 验收边界

请以当前本地固定版本核对目标权益可见、可选择及原卡欠款保持。此次未执行真实结账、线上验证；产品经理验收及后续提交、部署、推送均未发生。
