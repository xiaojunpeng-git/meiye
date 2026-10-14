# 第 3 轮组合卡固定版本与本地业务验收

- 基线 HEAD：`a8c2155d2ae10abd595377f9a2510c451acc847d`
- 业务源码、迁移及合同测试文件：33 个；工作区整文件 SHA-256 见下表；捆绑指纹为按路径排序的“路径、Tab、文件 SHA-256、换行”文本的 SHA-256。
- Git 追踪文件补丁 SHA-256：`38781f9c9227141556f052f0840fb0080a1d8612a124cc55baff2516c94414a3`。
- 本轮文件内容捆绑 SHA-256：`ed351312590487be0c43b6267b996f3cdbc6e8f0e9fb9352037449ab6fceb383`。
- 仅本地开发和本地库 `ruihao`（127.0.0.1:3307）执行组合卡迁移；未部署客户站、未提交、未推送。

## 精准提交候选

- 产品经理确认本轮本地验收通过，并同意从共用的收银主文件中剔除其他任务的权益合计、补交快照改动，仅提交组合卡相关代码。此候选中 `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` 的 SHA-256 为 `4313411468d3f285287161f3b99b1ad499f3d4b2f673d4393e3fb2b7486ff7da`；上表与文件指纹表保留真人验收时的完整工作区版本记录。
- 候选采用独立 Git 暂存索引；其他任务的现有暂存区和工作区未纳入。候选 SFC 解析、模板编译和暂存补丁空白检查通过。

## 本地业务结果

- 平台创建组合卡 `组合卡本地验收 20261014`：总部商品 606691、扬州欧尚门店商品 606710、卡号 5161025。三组分别是任选 1 种、共享 2 次、任选 1 种。
- 沿用既有结账步骤售出 ¥100，销售单 `XS26101400003`（本地账本记账，不涉及真实支付）。
- 使用共享组项目 `肩颈疏通(激活)` 一次，服务记录 `FW101400002`；第 2 组剩余 1 次，第 1、3 组保持原样。
- 使用任选种数组项目 `指尖胶原` 一次，服务记录 `FW101400003`；第 1 组选中种数 1/1，另一候选项目仍有物理次数但因种数限制不可再选，第 2 组仍剩 1 次，第 3 组未使用；父卡余次由物理项目合计 3 校正为当前最多可用 2。
- 核销事实两条留在本地库，金额分别为 0 分和 10000 分。未退款、未删除测试卡或业务记录。
- 完整卡仅允许整卡退款；本轮未对测试卡执行退款。
- 门店端权益列表的组合卡项目名前显示 `[组合名]`，窄列中换行呈现完整名称；普通卡项目名不变。组合名仅用于展示，不写入购物车或服务事实。组合共享组的金额投影按组去重，本地 5 个组合项目与 1 个普通项目已在页面核对。
- 平台组合卡编辑页的“添加项目”弹窗明确显示目标组合。重复选择卡内已有项目时提示原因并保持弹窗打开；改选新项目可以加入对应组合。本项仅在未保存表单内验证，临时加入的项目已移除。
- 门店收银在点击底部“确认完成服务”时，先核对同一张卡的同一任选种数组。本地把第 1 组的“指尖胶原”和“康养+幸福霜”同时加入购物车后，页面提示卡名、组合、最多 1 种、当前 2 种及需删除 1 种，并停留在收银页；未提交结账或新增核销记录。事务内的最终权益校验保持不变。

## 自测

- 平台端 `npm run build`：通过（既有资产体积警告）。
- 门店端 `npm run build`：通过，前置权益快照测试 70 项通过。
- `tests/platform-card-rules/php/card-rule-contract.php`：通过。
- `tests/cashier-v3/php/card-rule-entitlement-authority-contract.php`：14 项通过。
- `tests/cashier-v3/php/card-purchase-issuance-contract.php`：31 项通过。
- 组合卡父卡余次合同测试 3 项通过；卡项工作区绑定、权益适配器和退款额度合同测试通过。
- `tests/cashier-v3/php/combination-source-amount-contract.php`：2 项通过；校验共享组金额只计一次及投影的卡类型键不被组规则覆盖。
- 平台端真实页面操作：重复选择已有项目后保留弹窗和提示；改选新项目可加入组合，随后移除未保存的测试项目。
- 本轮 PHP 语法检查、`git diff --check`：通过。
- `tests/cashier-v3/js/combination-choice-preflight.mjs`：7 项通过，覆盖同组超选、跨组、同项目多行、已选项目、旧草稿补全及规则缺失；门店端按钮真人操作验证提示可读且未进入结账。
- 截图：`tests/platform-card-rules/evidence/combination-card-settings-local-2026-10-14.png`、`combination-card-preview-local-2026-10-14.png`、`combination-card-rights-after-writeoff-local-2026-10-14.png`、`combination-card-group-prefix-local-2026-10-14.png`、`combination-card-picker-duplicate-feedback-local-2026-10-14.png`、`combination-card-choice-preflight-local-2026-10-14.png`。

## 源码分组

- 前端源码：平台商品新增、编辑、详情、列表及组合项目重复选择反馈；门店端将组合卡接入既有卡项预览，准确显示组次数，在权益项目名称前展示组合名，并在进入结账前解释同组超选；结账步骤未变。
- 权益展示：组合卡父卡与会员汇总按目前最多可用的组次数计算；共享组购买和剩余金额按组去重；核销仍使用原事务。
- 后端源码：商品规则校验、售卡快照与签发、逐组核销状态、权益投影与金额、整卡退款约束。
- 数据库迁移：`后端代码/database/upgrades/2026-10-14-组合卡权益组/`，本地已执行。
- 自动化测试：上述 3 个合同文件、`combination-remaining-times-contract.php`、`combination-source-amount-contract.php` 及 `combination-choice-preflight.mjs`。
- 工程脚本：无。生成物：两端本地 `dist/`，不作为源码。验收证据：本文件及 6 张截图。
- 注释更新：平台卡项表单、门店卡项预览、权益项目展示、组合前置校验和上述后端服务中新增了组合归属、金额、核销、退款边界注释；合同测试补充了独立组合约束说明。

## 本次数据架构自检

- 权威来源仍是商品卡项配置及已售卡快照；收银前置校验只读取当前权益投影与本次购物车，不修改订单、核销或经营事实。
- 没有新增业务事件、时间口径、金额分摊、事务或并发写入；原有组合规则的项目唯一归属继续由保存校验约束，重复选择及同组超选在前端提前提示。
- 没有调整退款、作废、历史快照、数据权限、报表接口、索引或迁移；最终权益占用和并发结果仍由后端事务核验。

## 文件指纹

| 文件 | SHA-256 |
|---|---|
| `tests/cashier-v3/js/combination-choice-preflight.mjs` | `99ea59f157994f1182e7ac318af1acacbb92167d886db1a30fff21a16252e70c` |
| `tests/cashier-v3/php/card-purchase-issuance-contract.php` | `1dc1b5db2ab6c0b3e328e54016db064a96eac35077889c17177ffbe6fe00504e` |
| `tests/cashier-v3/php/card-rule-entitlement-authority-contract.php` | `ab18c6a89fe364db4fb65060f3374e8229ea67318f88de1627d40f9836a2b07e` |
| `tests/cashier-v3/php/combination-remaining-times-contract.php` | `00a859f8693a1d4631cc80f03af68deee584484f8cc75583e7a4a8347abb308d` |
| `tests/cashier-v3/php/combination-source-amount-contract.php` | `4ede554946b2a9d54619a05cd09e659c4bb0eff94ab31781808b346bf8fba295` |
| `tests/platform-card-rules/php/card-rule-contract.php` | `5f53e30d3a219ce0c5d76cfff4e7f2fb9ddb85f01924dc53e9d8027f8041ce87` |
| `前端代码/admin/src/pages/product/components/productDetails.vue` | `650b79394034d2aafbd0a4f7ef8eb709c6237819daa8ff707b28c782358c8390` |
| `前端代码/admin/src/pages/product/productAdd/index.vue` | `414138ed361ba7c4371fb112b8fa32a792976a2c7d6b98cca09af196bd00bb62` |
| `前端代码/admin/src/pages/product/productList/index.vue` | `2f6467f386201237b38140ab9d70ed6e5c1ea8a1eaea0ce8731ec1c755baf430` |
| `前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue` | `f3f9018031822383c6383e296127284e931cc96ceffafbb8020399912cab9dc2` |
| `前端代码/cashier-v3/src/services/cashierV3CombinationChoicePreflight.js` | `7bf8acbafeb3b610413b8d5552ff50ab87fede2525dfb1ee634beff454044d59` |
| `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `3d7119f7503477217336f2c4dc1063041e99208e454e55757cef2fa64e71ab5b` |
| `后端代码/app/controller/admin/v1/product/StoreProduct.php` | `b656a9f29401a6e46c1a83f36d6d1674e04c9e2636708a4bdd644f0cc31cd003` |
| `后端代码/app/model/product/product/StoreProduct.php` | `28188b925989f5381a288fc82fb0062c07b1def4ef37fe19961caf69f86f5fb6` |
| `后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php` | `d66d5c7d31db0abda04c35c273fd631011392d252cd5cac446b3f9769b6f017b` |
| `后端代码/app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php` | `8f4e6df5535e978190a6cdb1697a6c1ac60be363910e5341819556d9178e652e` |
| `后端代码/app/services/cashier/v3/card/CashierV3IssuedCardRuleStateServices.php` | `14700715c30d30e8d0f43ce18f5d9b9ddd3117a14fd8041dd528a0943a164c0b` |
| `后端代码/app/services/cashier/v3/cashier/CashierV3CashierMemberSummaryServices.php` | `5785ea158949073458b0043fccb3af552d92a1a5847590d79f3f3369208870f8` |
| `后端代码/app/services/cashier/v3/cashier/CashierV3CombinationRemainingTimes.php` | `34443034bc83bc04e8a6bde49e32e3ec5099cab1779435b8c4b9e31d032867c5` |
| `后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php` | `8716afec5f445c01b261d935450516c194ffc99fae36a641645bda588ca0a6a6` |
| `后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php` | `f4b1d06248b884b61f868cf55acd38a328b2df1396444ab44f9baf4c7cbba505` |
| `后端代码/app/services/cashier/v3/cashier/CashierV3SharedCardPurchaseAmount.php` | `cdd205f8ca7e4cde04f437f5a9bf278cbe834b9a04f7d6c428803a09c7b51646` |
| `后端代码/app/services/cashier/v3/cashier/ThinkPhpCashierV3SaleCatalogAuthority.php` | `df3d6dca3504be049e2a36c6f084c721f0bd39ea2c21e5860211bdd8fbb00309` |
| `后端代码/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php` | `83dc1be603dbea46ec0d646d1d3bd8a9525832803fea897b388e06c7bdef8828` |
| `后端代码/app/services/cashier/v3/checkout/CashierV3EntitlementCompletionKernel.php` | `eaf3391823271664b087211e59773d90193d2801652b878d5dbf92bddbc02b28` |
| `后端代码/app/services/cashier/v3/order/CashierV3RefundVoidServices.php` | `2bf777bf0b8975876e416e1cc97c306ceb50a188c4b656f9a4b3aa6c1423b077` |
| `后端代码/app/services/cashier/v3/order/CashierV3UnifiedRefundServices.php` | `00dad587e3c9236aeef4792687962d6ab42d92067523b1370c0c523548cb199a` |
| `后端代码/app/services/product/product/StoreCardRelatedServices.php` | `1ce892289a1e6fcab2e3867d6be26b59374978a55c2dda2c1f4a78ca7b1555b0` |
| `后端代码/app/services/product/product/StoreCardRuleServices.php` | `c196c9db0da4c07b3a9522905afed94e8df7a67fc87989f81913cc759a70d7b1` |
| `后端代码/database/upgrades/2026-10-14-组合卡权益组/00-升级清单.md` | `92bf21910c2a38417823e3dc4e3ae2d8e856c8cb75f7cd1075c88958afce6147` |
| `后端代码/database/upgrades/2026-10-14-组合卡权益组/01-升级前检查.sql` | `5a38ae800b4b149a7ba3dad597244a716a4b11b7386cf4acf99d1e9e43f5b25e` |
| `后端代码/database/upgrades/2026-10-14-组合卡权益组/02-正式升级.sql` | `67e92162899cbad76a8f3339b7e65cfe5147787081307632756caff7cb93cdaf` |
| `后端代码/database/upgrades/2026-10-14-组合卡权益组/03-升级后验证.sql` | `6e632cf4230b9bdfaf9e0f1bf4acc87407df658d7dc0305be9b8f3f381c04587` |
