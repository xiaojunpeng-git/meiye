# 第22轮：补交来源选择与更正本地自测

基线 HEAD：`a0dd16993a3a1bd9abb02f3dd7064c00c1c0489f`。本批修改仍在当前工作区，未提交、未部署、未推送。
本批已暂存文件差异指纹（按下表中已跟踪文件顺序执行 `git diff --cached -- <文件列表> | shasum -a 256`）：`2215c68af3cd242a1d25d8e5f45fe0218ecc5692b5dcf7f0035b8897e79bf70e`；新建文件另列全文件哈希。共用文件中其他任务的未暂存片段不属于本批。

固定文件 SHA-256：

```text
f9512a5a8feb8c2d759d28d68337984272431249a02b031881b1f0b4c33edd7d  前端代码/cashier-v3/src/components/member/MemberDebtOverlay.vue
b88cc6916de6eb0b87fe6c4195ed40b63312118868a19f56c4dd9beeb253e1e9  前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue
7acadd2d3c9e0d1c96ac1adcea6f9c8a58a45c58f9fc0e049ad08eaeb25fd12b  前端代码/cashier-v3/src/layouts/CashierShell.vue
42299ed5827057a17cb103819bb04e89e192842678532caad8741fd21035525e  前端代码/cashier-v3/src/views/CashierWorkbenchView.vue
82397599d5476d8ed959cfebb9cb035be6839af92a24322941892a3f5ad17589  前端代码/cashier-v3/src/views/OrderCenterView.vue
7c451f48b9aa3c3820a57e4b17cc8342b00e1390f0dceffbd4bfeb922c3f15ac  前端代码/cashier-v3/src/services/cashierV3ActionManifest.js
e30257e11ac0961df2203de94a24003e336e81197b0230fe2eef4bd132b0ad9c  前端代码/cashier-v3/src/services/cashierV3Bridge.js
d3405131a3f2e75d75a754cb005efe63449ff720aa5aa22cd5071b4697d7a785  后端代码/app/services/cashier/v3/settlement/CashierV3DebtRepaymentServices.php
bc3189acb3afca810a74e7f501df9eda754108ab08d4604d9ee12020a73c9a9b  后端代码/app/services/cashier/v3/settlement/CashierV3RechargeDebtRepaymentServices.php
c38894aa4a596e5d1f6b73967bd5c17bb1e64e70c89148044c249e48aa6bddcd  后端代码/app/services/cashier/v3/order/CashierV3OrderAttributionCorrectionServices.php
35dbe2fdb61d05dc3598c75283c182c03299ba166b3918ce882ea7efebb80586  后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php
d9ef062771cd457ad559732f706b11f0ea00c2f22b02902d7ff47bafc0bf5548  后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php
040fddeac089dd02a1a9fb5fa46e44e9ac1a8d199d00804c762bcabf362a3a97  后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php
624d85a9e071149a452670f57a7bd51bc42cc3f452519e651ab27b9c4e86f993  后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php
47dbbdfa9baf06be40913544371f53e324b125f9c6cbef85325d0568bba4e970  tests/cashier-v3/php/debt-repayment-source-correction-local-smoke.php
e3070b37d6cd36e1bfd0ad752cb332710a6ee685895dbfdc3558f634808a1741  tests/cashier-v3/php/order-source-snapshot-contract.php
b96f4463831cc68b80e16070c2fde9d12447b014c823c0595aeb96a31d88ac09  tests/cashier-v3/php/repayment-source-snapshot-contract.php
1bd819a4ae2671f04b5ace371918846aefec166aa16686069d4caebe7f89b148  tests/cashier-v3/js/debt-repayment-original-source-contract.mjs
306bb2678c87cf7a411683de3bfe4c363eeddc3a42888ca074cb9accfc82ccbf  tests/cashier-v3/js/order-attribution-command-context-contract.mjs
```

## 业务边界

- 权威主单：销售补交 `cashier_v3_debt_repayment`、充值补交 `cashier_v3_recharge_debt_repayment`；原销售/充值单来源仅作为本次补交的默认值。历史原单来源为空时，不猜测会员或工具栏来源。
- 成功终态：最终收款事务按选中 ID 解析原单历史快照或当前有效来源目录，主单与收款、余额、业绩事实保存同一来源。来源为空时前端“下一步”拦截，服务端最终事务也拒绝。
- 已完成补交：订单中心可单独更正客户来源。主单与相关事实同事务更新，原单及其他补交不动；变更记录保留前后值、操作人和时间。原单后续来源更正仍按既有规则联动其补交。
- 权限与并发：更正沿用消费订单功能权限、服务端强制门店、旧来源文字和 ID 比较、幂等键及审计；不增加单据业务版本。实际操作时间、收款金额、业务日期不因改来源变化。
- 报表：仍读取统一事实来源字段；本次没有增加前端统计或另建汇总口径。库存、真实支付和其他客户实例不涉及。
- 新客报表额外复核：新客资格继续读取首次销售生命周期事实的原单来源；补交清款读取补交收款事实自身来源。改补交来源只移动清款归因，不重新定义首次新客资格。

## 验证

- 门店端 Vite `npm run build`：通过。
- 历史停用来源的选项在切换其他来源后仍可选回；本次补充该前端边界并重新构建、运行合同测试通过。
- 涉及 PHP 文件 `php -l`：通过。
- 来源快照、订单来源、前端补交来源、订单归属桥接合同：通过。
- 既有 `business-source-checkout-frontend-contract.mjs` 仍断言已移除的“充值结账中途提交来源更新命令”，在进入本次补交断言前失败；没有把该旧合同计为通过，也没有为使它通过而恢复旧写入路径。
- 本地 `ruihao` 数据库回滚冒烟：对 `BJ26100400001` 的历史空来源模拟更正，核对主单、收款/业绩事实和详情审计后整笔事务回滚；`RRP-` 充值补交标识合同通过。数据库没有留下这次模拟更正。
- 浏览器真人操作：未完成。当前 in-app browser CDP 连接两次超时，未将编译/合同测试当成产品经理验收。

## 代码注释与文件组

- 前端源码：`MemberDebtOverlay.vue`、`CashierCheckoutOverlay.vue`、`CashierShell.vue`、`CashierWorkbenchView.vue`、`OrderCenterView.vue`、`cashierV3ActionManifest.js`、`cashierV3Bridge.js`。
- 后端源码：`CashierV3DebtRepaymentServices.php`、`CashierV3RechargeDebtRepaymentServices.php`、`CashierV3OrderAttributionCorrectionServices.php`、`CashierV3OrderCenterRecordQueryServices.php`、`CashierV3OrderLifecycleModule.php`、`CashierV3C5MemberOrderModule.php`、`CashierV3ActionManifest.php`。
- 数据库迁移：无，复用既有来源列和更正审计表。
- 自动化测试：`debt-repayment-original-source-contract.mjs`、`order-attribution-command-context-contract.mjs`、`order-source-snapshot-contract.php`、`repayment-source-snapshot-contract.php`、`debt-repayment-source-correction-local-smoke.php`。
- 工程脚本：无。生成物：Vite `dist/`（不纳入源码）。验收证据：本文件。
- 本次新增/更新了欠款弹窗默认值、目录加载、后端来源校验/事实写入、补交来源更正、幂等回放边界的职责及约束注释。

产品经理于 2026-10-04 表示“我测试没有问题”，并明确授权第22轮 commit、部署瑞昊、push。上述浏览器连接限制仍是 Codex 自测证据边界，不冒充代理已完成真实页面操作；产品经理的实际验收独立成立。提交与发布结果另行登记。
