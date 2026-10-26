# R10 旧卡录入本地自测与产品经理验收

- 本次复测时 HEAD：`60b5776be9f46d73335f9d38bd3d00201f88497b`（原开发基线 `a0dd16993a3a1bd9abb02f3dd7064c00c1c0489f`；共享工作区期间 HEAD 持续变化）。收银工作台当前完整补丁 SHA-256：`a8548db1d7122be26e46522f445297b2fc1b4596407d35fd67547a3073cefb8f`；该文件含本闭环先前改动，不可视作本次修复独占补丁。
- 口径：岗位显式授权后，旧卡录入仅用于卡项，单独结算；可与欠款同单，不与其他收款方式或余额支付组合。旧卡金额不写正式收款明细或现金业绩。现金退款被禁止，作废仍走既有冲销流程。
- 前端源码：`前端代码/cashier-v3/src/views/CashierWorkbenchView.vue`、`src/components/cashier/CashierCheckoutOverlay.vue`、`src/services/cashierV3Bridge.js`。
- 后端源码：`后端代码/app/services/organization/JobPositionPolicyServices.php`；`后端代码/app/services/cashier/v3/permission/{CashierV3FeatureResolver,CashierV3StaffFeatureOverrideServices}.php`；`settlement/{CashierV3CheckoutSettlementKernel,CashierV3CheckoutProjectionServices,CashierV3SaleOnlyCheckoutSubmissionServices}.php`；`settlement/payment/CashierV3PaymentCollectionPlanV1.php`；`order/settlement/CashierV3SalesOrderPlanV1.php`；`order/{CashierV3SalesOrderQueryServices,CashierV3OrderLifecycleServices}.php`；`fact/CashierV3SaleOnlyFactAssembler.php`。
- 自动化测试：`tests/cashier-v3/php/c2-checkout-settlement-contract.php`、`old-card-entry-permission-contract.php`、`old-card-entry-fact-equation-contract.php`。
- 数据库迁移、工程脚本：无本轮新增。生成物：Vite 本地 `dist/`，不作为源码交付。
- 自测：结账契约 84/84；权限 4/4；事实等式及收款条数回归 6/6；前端 Vite build 通过；修改的 PHP 文件语法检查通过；`git diff --check` 通过。
- 本次失败定位：本地 `20261004_error.log` 两次 `submit-checkout` 均在记账方式快照校验抛出“记账方式已停用或不存在”；本地 `ruihao.eb_cashier_v3_payment_method_config` 中 `old_card_entry` 原为 `status=0`，李倩账号 `A15358302092` 已有该岗位操作授权，因此不是账号权限缺失。页面现只在岗位授权且结账目录返回启用的旧卡方式时展示；目录读取失败则不展示，服务端最终校验继续保留。
- 本地测试配置：仅本机 `mohe-mysql/ruihao` 将 `old_card_entry` 一行由 `status=0, version=1` 更新为 `status=1, version=2`，供本地复测；未触碰远程实例或真实订单。生产配置是否启用仍须逐实例核对，不随源码自动开启。
- 第二次回滚定位：`20261004_error.log` 的 `sale_only_fact_payment_collection_count_mismatch` 来自事实组装器把旧卡录入草稿计入必须生成的收款条数。已修正为历史旧卡金额不计正式收款条数，同时保留普通收款漏记拦截；回归覆盖旧卡加欠款、纯旧卡、普通收款三种路径。
- 本地真实业务路径：复用此前失败的收银页订单，完成一张标价 ¥6,666 的卡项，旧卡录入 ¥5,666、欠款 ¥1,000；销售订单 `XS26100400011`。只读数据库核对：订单 `settled`，卡项凭证 `completed`，欠款单 `QK2610040001`，付款草稿 `old_card_entry/566600/committed`，收款批次 `settled` 且 `collection_count=0`、实收与现金业绩均为 0，正式收款明细 0 条。本次操作仅写本地 `ruihao`。
- 未覆盖：订单详情与作废联动尚未完成真实业务路径测试。现有付款计划测试夹具早于当前行指纹契约，启动即失败，不能作为本轮通过证据。结果页旧卡条目仍显示“待确认”，与已结算事实不符，需单独确认页面文案后修复。
- 注释：更新了岗位旧权限继承、历史卡非现金结算、最终事实金额等式、前端互斥控制及记账方式全局停用边界的相关业务注释。
- 验收：产品经理在本地测试后于 2026-10-04 明确“我测试没有问题,commit,部署瑞昊,push”；本清单所有文件 SHA-256 再核对一致。瑞昊发布前只读检查发现该实例旧卡方式为 `status=0, version=1`；源码发布不自动改变该全局停用配置，生产库启用须单独授权。
- 状态：本轮固定源码版本已获产品经理验收；本记录形成时尚未 commit、部署或 push。

## 文件 SHA-256

```text
0d49c11864d1b7473c1658cd704e914d6b1380955e85b6c81e341c20a6cd3e0a  tests/cashier-v3/php/c2-checkout-settlement-contract.php
332eae6d03b56fc164d27d1dff40315e4f92877567d8ce984a762c8d2aa7281f  tests/cashier-v3/php/old-card-entry-permission-contract.php
a370f17c6cf11bd5abc968ff393953615543876fd8503d9f5a23237c7b38efbb  tests/cashier-v3/php/old-card-entry-fact-equation-contract.php
6d17fbf8ea5b7affb79c59d7054357867eca8e00ce1f8f6ef2aee8c23ff98f3a  前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue
803a73195ceb388a4f0e7e9f8d4d0ec02ef48664e2bc8f783bd157a61fea924d  前端代码/cashier-v3/src/services/cashierV3Bridge.js
0a6dedea78cbd298bdc58169d37cab5fc6969ea2ff329a6ad5867e0e1172b06d  前端代码/cashier-v3/src/views/CashierWorkbenchView.vue
42498c967de76bd90680333c9870e0eb84abea0386278425d325a9f2c43a0972  后端代码/app/services/organization/JobPositionPolicyServices.php
2e56e86236e88cbb5591eb92264a9df232822fdabe8ad5479ecb994947f41aba  后端代码/app/services/cashier/v3/permission/CashierV3FeatureResolver.php
9e209daf5e726ecbfb5bc29ddfa952821bec7c19c56ac58b5e7b368596a3845b  后端代码/app/services/cashier/v3/permission/CashierV3StaffFeatureOverrideServices.php
400410bdd4611ba6058aa3e52c9805238088fe6c871d100e48699f58e0e0940a  后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php
db9bf3eb3330c4e2a9c3aa9fc081edf10f8d8b34df185874395ece4a1a64c22c  后端代码/app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php
a0409f6f0becaed01129d6ebda49fececcd44d9f491686a72112c431cd41e5fb  后端代码/app/services/cashier/v3/settlement/CashierV3SaleOnlyCheckoutSubmissionServices.php
30e37656d6de671daa8ec62679359d997710c5b49615bb3f205f3939324ec4b2  后端代码/app/services/cashier/v3/settlement/payment/CashierV3PaymentCollectionPlanV1.php
e52635a8a768bbc43b89b99368a8e112300f40618ba6ca3733f4d0891c4453f8  后端代码/app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php
a95955fd941e788d4f3e04a318588bc6bc966232f1fb8e949583a93995e78657  后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php
95416c11ee2ed2d3117c0ec59b6196f053f7127ec9dde1704d762f455fbd32f5  后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleServices.php
13a4234126e0b4052d370e3c9dfd07dadf7eb18b295148a754c976372af9285b  后端代码/app/services/cashier/v3/fact/CashierV3SaleOnlyFactAssembler.php
```
