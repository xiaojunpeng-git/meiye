# 第22轮：订单业务日期与来源更正，本地固定验收记录

状态：本文件记录第22轮归属更正的本地七笔验收数据与当时固定版本。产品经理于 2026-10-04 表示“我测试没有问题”，明确授权本轮 commit、部署瑞昊、push；后续新增的补交来源选择／更正见 `R22-debt-repayment-source-local-selftest-2026-10-04.md`。本文件记录时未 commit、未部署、未 push，线上实例和线上数据库均未修改。

## 范围与数据口径

- 正式销售订单：列表业务日期、来源为蓝色可点；纯服务订单只开放业务日期，来源保持只读；充值订单可改业务日期、来源；本阶段补交记录先开放业务日期，随后本轮增加了来源更正。沿用消费订单权限，不增加独立入口或单据／事实版本。
- 一次事务更正主单和关联的统一事实；原单来源同步其已发生补交来源，原单业务日不移动补交业务日。实际发生、支付完成、冲销等独立事件时间不改。
- 详情展示变更记录：旧值、新值、单号、操作账号快照及真实操作时间。集团经营日物化汇总在旧、新日期重建。
- 充值主单新增业务日并回填。历史充值无 V3 收款事实时，不补造收款事实；列表日期按上海时区回填和筛选。

## 留存的七笔本地验收记录

| 类别 | 门店 | 单号 | 日期旧 → 新 | 来源旧 → 新 |
| --- | --- | --- | --- | --- |
| 消费 | 江苏金湖桓裕店 | XS26100300074 | 2026-10-03 → 2026-10-02 | A老客首护 → B导购引流 |
| 消费 | 南平胜利街店 | XS26093000010 | 2026-09-30 → 2026-10-01 | A老客首护 → B导购引流 |
| 充值 | 霍山女人街店 | CZ26100200001 | 2026-10-02 → 2026-10-03 | A老客首护 → B导购引流 |
| 充值 | 肥西保利广场店 | RH-RELOAD-102-RECH-0091033179 | 2023-03-01 → 2023-03-02 | 未记录 → B导购引流 |
| 补交 | 安阳仁义店 | BJ26100100013 | 2026-10-01 → 2026-10-02 | 不单独改 |
| 补交 | 安阳仁义店 | BJ26100100011 | 2026-10-01 → 2026-10-02 | 不单独改 |
| 纯服务消费 | 蒙城凤凰城店 | FW100301126（同结账组另含 FW100301127） | 2026-10-03 → 2026-10-02 → 2026-10-01 → 2026-10-03 | A老客首护保持不变，禁止编辑 |

第二笔消费关联补交 `BJ26093000004` 的来源已联动为 `B导购引流`，补交原日期 `2026-09-30` 未移动；原单详情和补交详情均能读取该关联变更。以上操作在本地服务层使用模拟门店操作上下文执行，审计中的员工 ID／姓名是测试上下文，并不表示该员工本人执行；审计行及业务记录保留，未清理。

纯服务消费以结账请求 `CKR-fc8ed3ee0ae98d0891edea1785d82d9e793b7902` 为权威组标识。首次本地脚本更正操作号 `OAC-08C0E721C281387634FD62D9A00EC8BCDFADB25D`；产品经理随后在页面把日期从 10 月 2 日改为 10 月 1 日，操作号 `OAC-FDDA6B0E89CD45B27374888FBF3A4E4DE66ECDE1`；验收期间又改回 10 月 3 日。2026-10-04 复核时三条审计均保留，当前业务日为 10 月 3 日。本地更正同步更新结账请求、完成凭证、2 条服务事实、2 条核销事实、6 条业绩事实及 1 条到店生命周期事实；原结账真实发生时间、来源选择 A 与后续独立事件均未更改。首次审计显示操作人快照“方影”（原单操作账号；首次由本地验收脚本代为调用业务服务，并非方影本人操作）。

## 技术验证

- `02-正式升级.sql` 已仅在本地 `ruihao` 执行；重复执行无错误。`03-升级后验证.sql`：充值业务日空值 0、审计表存在 1、11 条可读变更行（10 次主操作 + 1 条关联补交来源）。
- `order-attribution-local-smoke.php`：试运行在事务中逐项更正并回滚；正式保留后 `--verify-six` 复查六笔主单、关联补交、事实日期与来源、详情变更记录，并确认跨门店读取和旧值重提交被拒绝。
- `CashierV3Bootstrap::selfCheck` 为 `ok=true`，三项更正命令和两项查询均已激活；相关 PHP 文件 `php -l` 通过；门店端 Vue 源码 `npm run build` 通过。集团经营日汇总对门店 18、164、55 的相关日期检查 `stale_group_count=0`。
- 18091 本地页对江苏淮盛店 `XS26100400008` 的浏览器复测发现：列表已收到 `correct-sales-order-attribution`，但前端误从叶子操作权限读取页面级 `cashier.v3.order_center`，使蓝色入口隐藏。修正为页面 feature 检查后，日期和来源均显示可点击按钮，分别打开相应更正弹窗；测试只打开并取消，未改动该订单。提交保存流程仍待产品经理界面验收。
- 产品经理随后在该订单提交日期时发现 `INVALID_COMMAND_CONTEXT`：前端通用网关仍误把此类后端锁单、旧值比较的更正命令当成需要浏览器资源版本。已把正式消费、纯服务日期、充值、补交四类更正加入服务端锁定的空 contexts 合同；独立 `order-attribution-command-context-contract.mjs` 验证四类命令均到达适配器且不携带虚构版本，本地 Vue 重新构建通过。原失败请求没有写入：`XS26100400008` 日期仍为 `2026-10-04`，该单变更审计为 0 条。
- 纯服务订单 `FW100301126` 原先的日期、来源均不可点，是因为列表把这类权益结账的 `availableActions` 设为空。产品经理最终明确“纯服务记录就不能改来源”，因此仅增加 `correct-service-order-date`：业务服务按结账请求锁定整组、比较旧日期并拒绝来源字段，列表只授予日期动作。事务回滚测试和正式本地留存后的 `--verify` 均通过；后端投影在 10 月 2 日返回日期动作且没有来源动作。18091 浏览器在实际刷新后重新查询 10 月 2 日，`FW100301126` 的日期为蓝色按钮，来源 `A老客首护` 为普通文字；点击日期能打开更正弹窗，详情可见 `2026-10-03 → 2026-10-02` 的变更记录。
- 产品经理在页面再次更正为 10 月 1 日后，成功提示作为额外子项插入订单页固定四行网格，使查询栏误占剩余高度，表格被挤到底部。浏览器读取到查询栏高度约 640px、列表约 165px；订单页调整为纵向 flex 后，成功提示存在时查询栏约 73px、列表约 731px，刷新后无提示时查询栏仍约 73px、列表约 791px。本月筛选 `FW100301126` 可见，详情保留连续日期更正记录，来源仍为 A。Vue 构建与 `git diff --check` 通过；纯服务日期脚本按当前数据库复核完整审计链，2026-10-04 再次复核当前最终日期为 10 月 3 日，不把首次目标日期固定为最终值。
- 既有 `order-center-sales-readonly-contract.mjs` 因未改动的 `UnifiedQueryToolbar.vue` 文本匹配断言失败；本轮不改该组件，也不把此项记为通过。产品经理仍需按上面六笔验收。
- 旧 `bridge-contract.mjs` 当前报告 31 项失败，主要夹具根状态缺 `readOnly`、`sessionMode`、`operationPermissions`，无法作为本轮通过证据；本轮使用完整桥接根状态的独立合同测试已通过。
- 婺源秀水华庭店 `2026-10-01` 至 `2026-10-04` 无统一消费事实及正式销售单；同期检出的 6 笔 `store_order` 全为零元赠送，因此会员消费明细为空不是本次更正造成的。

## 固定代码边界

记录时 Git HEAD：`2cbecf4b742089264d0640dedae23250d325fc6f`。工作区有其他任务的脏文件，本轮未暂存或改动它们。下列业务源码变更及 SHA-256 为本次待验收版本；本轮相关跟踪文件补丁指纹 `fdcef7d6ef5ae5cf31fb1bced3946922acd74f551769a9bf8432433aa57c2bd8`。

前端源码：

- `前端代码/cashier-v3/src/styles/base.css` `e4ba721b62bea1d2e5bbdecb82450a456fc8e7da7a87fbe18ee3862f0ab08a1c`
- `前端代码/cashier-v3/src/views/OrderCenterView.vue` `39b331d6dbd6c1be26d8b164864df6412bd66ca7ceed9c226d5a4cff7ea07906`
- `前端代码/cashier-v3/src/components/order/BusinessRecordDetailOverlay.vue` `748632a57632e786e2f748e8b52aed02011de41269ccf6227c6d011e171b1449`
- `前端代码/cashier-v3/src/components/order/SalesOrderDetailOverlay.vue` `48369314d73d5fdf3fa225d2cdde95e1be84872060f16074259f8a7871383964`
- `前端代码/cashier-v3/src/services/cashierV3ActionManifest.js` `b8b55fd24fd4ba6daef54401a9455cf29d0a1931de4bb05a4f65674773015c25`
- `前端代码/cashier-v3/src/services/cashierV3Bridge.js` `fad36d0d68403050316b70011b8f72ddb217c6477c59f385da51c50ec9336944`

后端源码：

- `后端代码/app/services/cashier/v3/order/CashierV3OrderAttributionCorrectionServices.php` `c76f99f79dc7da739988bc98bc2ee35fab06e7bba3819a80ddecd38cced222f8`
- `后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php` `f1dbfd3fb2214a0f8a0a6822ab1ec8ae94b35a947781875db5208f6f7eade5f6`
- `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` `23f32cf78e35bfec4134c1d0de63281da0837a26b6416318360718d61b5b1502`
- `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php` `304d7e8d863262d088f3b29aa9493a010aead59137c1f997bda35d9aaddb288a`
- `后端代码/app/services/cashier/v3/member/CashierV3RechargeModule.php` `af55e64c71eeff546da62b566ece7f2912680ca929f81d9d5e679ceb1c4aa18a`
- `后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php` `0cae0d5e9c06cd87aae5c5502846883d24ac59431fdf2a98ed35e524b098d0bd`
- `后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php` `dc7d877f9bb17abbdc4f62047a34c555213ff7811f06fede1a7416537f5fece9`

数据库迁移：`后端代码/database/upgrades/2026-10-04-订单归属更正/`（说明、正式 SQL、验证 SQL）；自动化测试：`tests/cashier-v3/php/order-attribution-local-smoke.php`、`tests/cashier-v3/php/order-attribution-service-date-local-smoke.php`（SHA-256 `585f047f882274e04c5bea6e03d0848b1c77e6540c423262ed51e69315aab7e2`）、`tests/cashier-v3/js/order-attribution-command-context-contract.mjs`（SHA-256 `43ab85522c3ce2ba8efb4e7e0eb58798727e09f779ac304d733aa15dc5a0b22c`）。工程脚本：无。生成物：本地 Vite `dist/`，不作为源码或提交文件。验收证据：本文件。
