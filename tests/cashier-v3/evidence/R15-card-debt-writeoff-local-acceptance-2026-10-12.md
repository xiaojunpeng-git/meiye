# R15 卡项欠款禁止核销：本地自测与固定版本

## 范围与版本

- 基线 HEAD：`75101a3d6b9d5f48fdf82d0214cef0b00b2ea11e`。
- 已售卡购买时冻结“欠款不允许核销”开关；默认关闭，旧卡不追溯。开启后仅该卡来源待还额大于 0 时拒绝核销，结清后恢复；同会员其他卡不受牵连。
- 未修改收银页面。仅本地 `ruihao` 库执行新增列；瑞昊线上未改库、未部署。本轮未 commit、未 push，待产品经理对本固定版本验收。
- 工作区同时存在其他任务的脏文件及暂存文件；本轮没有清理或纳入它们。`CashierV3EntitlementProjectionServices.php` 中 `o.uid` 过滤为既有他人改动，本轮不认领该处。
- 已修改业务代码的职责/快照/只读缓存/提交实时校验注释随代码更新：`productAdd/index.vue`、`StoreProductServices.php`、`CashierV3SaleCatalogServices.php`、`CashierV3CardPurchaseIssuanceServices.php`、`CashierV3EntitlementProjectionServices.php`、`WriteOffOrderServices.php`。

## 真人 UI 路径（本地 18081/18091）

1. 平台商品 `252822`（门店 175 的“单次体验卡”）打开开关并保存；数据库配置为 1，售出卡组件快照也均为 1。
2. 新建仅供本地自测的会员 `1136878`。购买第一张卡：订单 `XS26101200002`，成交 99 元，实收 49 元，欠款 50 元；签发卡号 `1025625`，旧单 `2325207`。进入“使用权益”时该卡余次为 0，项目按钮禁用并提示“该卡有欠款，请先结清”。
3. 同会员购买第二张卡：订单 `XS26101200003`，成交与实收均为 99 元，欠款 0；签发卡号 `3707481`，旧单 `2325208`。会员仍欠第一张卡 50 元时，第二张卡可选；实际使用“通(背/腿)”一次并完成服务。首次提交遇本地 PHP 热重载 502，查询确认无凭证、无扣次后才重试；最终仅有一张完成凭证。
4. 通过页面对第一张卡还清 50 元；债务记录 `325650` 为已结清、已还 50 元。重新进入“使用权益”，第一张卡恢复余次 1、欠款 0，实际添加“通(背/腿)”、选择手艺人并完成服务。页面返回“权益使用成功，项目核销已完成”。[成功页](r15-repaid-card-writeoff-success.png)。
5. 最终数据库核对：卡号 `1025625` 与 `3707481` 各自 `write_times=1`、`write_surplus_times=0`；两张旧单各有 27 个项目且各仅发生 1 次扣减。会员仅有两张完成凭证 `ECR-4e852a13c75be1dc8ed0a45a8f6be29781de32f0` 与 `ECR-2210c0df74b64fef16ccb9604919fe7e78722f67`，均为 `completed`、1 行、1 次服务。

## 技术验证

- 本地升级后 `eb_store_product.block_writeoff_on_debt` 为 `tinyint unsigned NOT NULL DEFAULT 0`，无 NULL/越界值。
- `r15-card-debt-writeoff-policy-contract.php`：欠款禁用、结清恢复、旧卡不追溯、来源不符拒绝，4 项通过。
- `card-purchase-issuance-contract.php`：31 项通过。
- `reservation-debt-limited-writeoff-contract.php`：3 项通过；顺带修复旧夹具签名及显式只读公式输入，未改生产口径。
- 本轮 PHP 文件语法检查、`git diff --check` 均通过；平台端 `npm run build` 成功，仅有 Browserslist 过期及原有大资源提示。构建产物属于生成物，不纳入源码清单。
- 27 项目的权益列表曾出现本地响应超时。本轮只读投影按订单/持卡记录缓存相同债务、取消额与共享上限，提交时仍实时锁定核验；修正后页面加载并完成还款后核销。

## 文件清单与 SHA-256

前端源码：

| 文件 | SHA-256 |
| --- | --- |
| `前端代码/admin/src/pages/product/productAdd/formModel.js` | `4760d0d6e6a98b2070651ab70d2df7bbe7836accac773209671e21068ca1ab7d` |
| `前端代码/admin/src/pages/product/productAdd/index.vue` | `0593740897b3c90ef086b01dd7dcce5023458f1f40e18e4ec8bd1a3cdb522419` |

后端源码：

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/app/controller/admin/v1/product/StoreProduct.php` | `6014cfda5c5232b765892f889525b370137585acee93e2655bbea334eeae61c4` |
| `后端代码/app/services/product/product/StoreProductServices.php` | `d8dab24df56cf41f7e7e1ab405a5a7af9f17f4b64e63b9391ea8461bd8459848` |
| `后端代码/app/services/cashier/v3/card/CashierV3CardPurchaseIssuanceServices.php` | `62cd5b2092eb9049e87d9b8188064a0ae5887cce36b5f3e18bce753278ea2df0` |
| `后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php` | `9ecbdfe69ba93a10532c632a1cbbb42bc5f04b26a289ef729cb1c8fae043516d` |
| `后端代码/app/services/cashier/v3/cashier/CashierV3SaleCatalogServices.php` | `5dd7d7acc71f73170a7fdedb2b5b413bc9a45bf443bb4ed07c6ebabe46ece1c4` |
| `后端代码/app/services/cashier/v3/cashier/ThinkPhpCashierV3SaleCatalogAuthority.php` | `71a8de03a1df182a425b1876050f32952c52d4792037de7be2d58f709b1bf1da` |
| `后端代码/app/services/order/store/WriteOffOrderServices.php` | `b1e84366eeaead73bc8638e38f5464ec8b27ff375c5da4d8b77d33cdfe983805` |

数据库迁移：

| 文件 | SHA-256 |
| --- | --- |
| `后端代码/database/upgrades/2026-10-12-卡项欠款禁止核销/00-升级清单.md` | `265ad27320dbbe31dc4a98b8dac5f24ac5661980805c78f3a15643cafa102c3a` |
| `后端代码/database/upgrades/2026-10-12-卡项欠款禁止核销/01-升级前检查.sql` | `4c64586ca2d3036c4032b4c996a5977416fe2ab66e5f5651ce5fd794d8ab3e80` |
| `后端代码/database/upgrades/2026-10-12-卡项欠款禁止核销/02-正式升级.sql` | `0b63d1710ff6e87be5ed0e84b777861cd5ae95e56596c16d3cdc92d3b0a4ed1c` |
| `后端代码/database/upgrades/2026-10-12-卡项欠款禁止核销/03-升级后验证.sql` | `5131b955556b5a0ccbbf4f078673e10fcdb3bd6d6a3a93674c7264069755b165` |

自动化测试：

| 文件 | SHA-256 |
| --- | --- |
| `tests/cashier-v3/php/r15-card-debt-writeoff-policy-contract.php` | `5e023bade817d61b38989a5ae430ac47a30466c17ee1f0cd82df905af4b882a1` |
| `tests/cashier-v3/php/reservation-debt-limited-writeoff-contract.php` | `82afa711fac77fab474bfe3b29a02e473c9f813a89f004afad7a7b1e9d4c2acc` |

工程脚本：无。生成物：平台端 `dist/view_admin/`（忽略目录）。验收证据：本文及 `r15-repaid-card-writeoff-success.png`。上述已跟踪源码补丁 SHA-256：`89cb4c20a5f055e6ace8b1a9292d5f6bb69cebba46e5a2b47598c60c37159be1`。
