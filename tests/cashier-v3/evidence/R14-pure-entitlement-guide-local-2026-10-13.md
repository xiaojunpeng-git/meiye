# R14 纯权益护理导购与地推独立行：本地固定版本

- 基线 HEAD：`3c293d3e1b97`；本次未 commit、未部署、未 push、未操作远程数据库。工作区包含其他任务的未提交改动，尤其报表服务文件，不可按整文件自动暂存。
- 业务范围：纯权益结账的权益项目可选择导购及无轮次/第 1～3 轮；不开放销售人或销售经理，不生成销售或现金业绩。完成核销后导购事实与权益回执同事务写入，作废服务不占用有效轮次。
- E 地推拓客明细：纯权益结账无销售单时，以已完成、未作废、非体验、当次 E 来源的护理事实独立成行。每个服务事实只投影一次；已有销售行排除本期已独立成行的护理。来源优先使用护理冻结快照，旧空快照回退当次选择；导购轮次按护理行自身事实归栏。纯权益行现金列为 0。
- 汇总与补充字段：汇总会员名单包含独立护理行，首护日期取最早 E 护理，年度现金仍来自原收款事实。纯权益行手动字段使用 `service:<service_fact_id>` 稳定键，保存时反查服务及当前权限；销售行沿用销售明细键。报表查询、合计与导出共用同一投影。
- 权威源：`cashier_v3_checkout_request`、`cashier_v3_entitlement_service_fact`、`cashier_v3_entitlement_completion_receipt`、`cashier_v3_customer_guide_round_fact`；现金仍取原销售收款分摊。无迁移、无生产数据回填。
- 注释：本次补充/更新了收银工作台、结账准备/内核/投影/提交、导购轮次服务、报表服务和补充字段服务中的业务边界注释。

## 自测结果

- 浏览器本地 `18091`：权益项目加入购物车后有“导购”按钮，弹窗只显示导购选择；截图为 `R14-pure-entitlement-guide-selector-2026-10-13.png`。关闭后移除测试项目并返回原视图，未结账。
- 前端 `npm run build` 通过；存在 Vite 既有大包提示。
- `c2-checkout-settlement-contract.php`：95 断言通过，0 失败；PHP 当前版本对既有隐式 nullable 参数给出 deprecated 提示。
- `cashier-entitlement-attribution-contract.php`：6/6；`guide-round-fact-contract.php`、`phase-two-report-projection-contract.php`、`personnel-performance-overlay-contract.mjs`、`local-checkout-entitlement-snapshot-contract.mjs` 均通过。
- 本地 Docker 只读查询：E 来源/非 E 排除、无轮次/第一轮、汇总、导出通过。
- 本地 Docker 事务回滚：纯权益 E 服务独立一行、销售行不重复、选导购归第一轮、0 现金、手动字段保存后刷新可读、汇总与导出一致；改成 A 来源不再入表，回滚后无测试事实/导购/补充字段残留。权益导购事实持久化与历史轮次预检的独立回滚测试通过。
- `php -l` 涉及报表、注释服务和新测试文件通过；`git diff --check` 通过。
- 已知缺口：`checkout-personnel-closure-contract.mjs` 的旧静态断言期待 `localCheckoutPreview.value = localCheckoutPreviewSnapshot()`，而基线 HEAD 已是 `const preview = localCheckoutPreviewSnapshot()`；该测试在本轮仍失败，未擅改其他任务维护的断言。未执行真实纯权益结账，以免写入门店业务数据。

## 文件分组

- 前端源码：`前端代码/cashier-v3/src/views/CashierWorkbenchView.vue`。
- 后端源码：`后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php`、`后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutPreparationServices.php`、`CashierV3CheckoutSettlementKernel.php`、`CashierV3CheckoutProjectionServices.php`、`ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php`、`后端代码/app/services/cashier/v3/report/CashierV3GuideRoundFactServices.php`、`后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php`、`StoreOperationsReportAnnotationServices.php`。
- 自动化测试：`tests/cashier-v3/php/c2-checkout-settlement-contract.php`、`cashier-entitlement-attribution-contract.php`、`guide-round-fact-contract.php`、`phase-two-report-projection-contract.php`、`field-acquisition-service-round-mysql-integration.php`、`entitlement-guide-round-rollback-mysql.php`、`field-acquisition-entitlement-only-rollback-mysql.php`、`tests/cashier-v3/js/personnel-performance-overlay-contract.mjs`。
- 数据库迁移、工程脚本：无。生成物：Vite `dist/`，不作为源码；验收证据：本文件及页面截图。

## 文件 SHA-256

```text
0bd78c900f136fb09aa17f3095ae8b0996331d147c19902309aa6d1381ff8e64  CashierWorkbenchView.vue
8efdc4da3ab7dff41b04ec7276721254e797cc044073a75a6c738fcc16c9da4a  CashierV3CashierWorkspaceServices.php
caf0b398f297abaae0bb1d4af0e6ff1f29e1e5d817123e005fcfea401580691e  CashierV3CheckoutPreparationServices.php
425734625606833bd52b964903d8180c975889373bedfba579a274fab9eccdf9  CashierV3CheckoutSettlementKernel.php
c216bc678476901a0eea843051f5224f557dff54323cfadfb5247dd9df014518  CashierV3CheckoutProjectionServices.php
98da9668933f3f0f757bb44d2c6110852866da8c973bddff8d19ccdf829d6321  ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php
72dc861abc022243138451175355bdd2131886336724d6f4be4c6c575a5194ce  CashierV3GuideRoundFactServices.php
b2b127d5b214bc455f198999e6622c7970bd85b1b3e1574f13c8859a439ce9e9  StoreUnifiedReportPhaseTwoServices.php
bb2fb40a6e8cf03f8ab4f51e5f5e7ba322b5a9b0f327713cbed00a1ea8ed6779  StoreOperationsReportAnnotationServices.php
ac8144ef2f4d58f2f8843131d291a34bc245f86ad674062e93d86eefcfcc30be  c2-checkout-settlement-contract.php
c45b951d17384d0233300139f3d6b99bc2460d6c89f328b7fb5b3b03c76c4c34  cashier-entitlement-attribution-contract.php
2d07f7f05967e52fc378f5398962ffc58a046714b47d00964b549c5d0ea77d9a  guide-round-fact-contract.php
c400d0fcbb68fa9e61e8bfb9ad259a1673a85d9c8c5f6f5ab893da6cf3c7899c  phase-two-report-projection-contract.php
3f5e85b0bd0ef1e3e2501d122300cab7053ed68642a320ca885d59e753fa7fe4  field-acquisition-service-round-mysql-integration.php
052c6872d0b0110930eb2860981c8bb5fc7d1fa72242238a3a010ceabf03965b  entitlement-guide-round-rollback-mysql.php
f8308f26e4e0e593c8cc937c0662e90e2aff146ccb60ab7f3b918ddab7b3c91d  field-acquisition-entitlement-only-rollback-mysql.php
fdf8534e1f35b0920f3a4e586448a71e4d9ead5955e4ec21ab89192914e5c364  personnel-performance-overlay-contract.mjs
b2399f180d45263cb965bd729bdcd5069015354c7f25f9ec4b39ccd4f5e597fd  R14-pure-entitlement-guide-selector-2026-10-13.png
```

状态：开发与本地自测完成，待产品经理按上述固定版本确认验收。验收前不提交；部署、远程改库与 push 均未获本次授权。
