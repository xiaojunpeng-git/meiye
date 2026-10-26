# R20 会员领用记录：本地自测

- 源码正本：`美容源码/`；基线 HEAD：`4d49aa7b8f2fff6abe5ba714a8efc1a0c22d340f`。本轮尚未提交、部署或推送。
- 固定文件清单 SHA-256 汇总指纹：`e10030692241832e508faf449db8a2782ed0d30c00723292d390865b400c0a4e`（按下列八个文件的顺序计算）。
- 业务边界：以每次 `cashier_v3_presale_claim` 为一条记录；`claim_no` 在同一事务中登记为 `inventory_business_document_no.document_no`。列表只读取已授权门店，作废仍走现有库存原批次退回事务。
- 前端源码：`CashierShell.vue`（页签局部修改；该文件还有其他任务未提交改动）、`PresaleClaimView.vue`、`presaleClaimApi.js`。
- 后端源码：`CashierV3PresaleClaimServices.php`、`PresaleClaim.php`、`StoreCkeckRoleMiddleware.php`、`route/store.php`。
- 数据库迁移：无。自动化测试：`presale-claim-records-readonly-integration.php`。工程脚本：无。生成物：本地 `cashier-v3/dist/`，不纳入源码。验收证据：本文。

## 验证

1. 四个 PHP 文件语法检查通过；`git diff --check` 通过。
2. 门店端构建通过，预构建结账唯一快照 70 项检查通过。
3. 既有预售/赠送领用前后端合同检查通过。
4. 只读本地集成检查 11 项通过：记录和库存登记单号对应、来源/状态/日期筛选、空权限与跨店隔离、分页总数。
5. 在 `18091` 门店端真实页面查看到 5 笔记录，含预售和赠送；按出库单号筛选得到 1 笔；详情显示同一出库单号与会员/商品；作废按钮能打开填写原因的确认框，未执行作废。

## 固定文件 SHA-256

```text
CashierShell.vue                              2e38771525eb486cee34c479e9935341fb805082c930f498f070384b101fa69b
PresaleClaimView.vue                          9600baa7f1e2b8e0e300c229ee10ceae8f95f4b19ca3ab330b0c17c99dd21697
presaleClaimApi.js                            d9e3eea1fc6834d4508f4a546a1806c8343296b5e502a90c82cc207338978056
CashierV3PresaleClaimServices.php             7d470a55faf303510debde461ec45950f747036c8814610bfbcaf9740606b951
PresaleClaim.php                              d6e80a08cab38f51113132342a2cbbe899fef7bc706e68e03a45da2a8d97b483
StoreCkeckRoleMiddleware.php                 003998a4d69feac916a708e20743e012a7861d35d84ba1e938b8f44476b29998
route/store.php                               106d32d3adbe3beeb875fcd3f46e0c19fd4d5ab2a56724362c9d3886a0e06cd8
presale-claim-records-readonly-integration.php 00d05c577a6fdfbe6b61de5191d060ea5a89954b1ad2773517b96eef8dca956f
```

新增业务注释位于领用记录服务、接口、前端加载/详情逻辑和页签样式，说明事实粒度、权限范围及号码来源。
