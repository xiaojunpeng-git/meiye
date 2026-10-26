# R14 欠款取消恢复、可用余次及卡升级门禁：扩展固定版本

- 基线：`release/store-cashier-c14-main` / `ad686ca774fdd31a96bf6d92257d265c6b8a8d14`。
- 状态：开发和本地自测完成，待产品经理对本固定版本确认验收；未 commit、未部署、未 push。旧证据 `R14-debt-cancel-restore-fixed-2026-10-05.md` 为此前较窄的版本，不代表本扩展版本已验收。
- 口径：原始权益次数不改；1000 元 10 次欠 450 元，待还或取消后可用余次均为 5。项目替换/升级不能超过可用次数，共享卡还限制多项目合计。整卡转让仍允许且债务限制随卡展示。单卡、项目及多卡升级若原卡尚待还，提示“请先补交原卡的欠款或者取消原卡的欠款，才能升级。”；取消后升级不把取消额计入抵扣。
- 以下文件清单按所列顺序的 `shasum -a 256` 输出再次 SHA-256：`0cfd844e85f34173667416ebf2d16b073be6687f8134aa784df8ee6617b4106c`。所列已跟踪文件的当前 `git diff --binary` SHA-256：`78eba7045984a8d39e4f4f1b81f2c1f15c03345551024ca2683af1afbcc68aa4`。本证据文件不计入自身指纹。

## 闭环文件

| 分组 | 文件（相对于 `美容源码/`） | SHA-256 |
| --- | --- | --- |
| 前端 | `前端代码/cashier-v3/src/components/order/BusinessRecordDetailOverlay.vue` | `d2fb1f8af46aa3c8ef21001be4f320c50f453fe66f62ed46ae9337710facc558` |
| 前端 | `前端代码/cashier-v3/src/services/cashierV3ActionManifest.js` | `99e003c36b713148a13e47c00231fe0c34bbc2636138d3e75db035ac6c58d99e` |
| 前端 | `前端代码/cashier-v3/src/services/cashierV3Bridge.js` | `7a979aa566a33474a2f92e61ea428e93131230fd08b94b6028ac2ce4ff4058a6` |
| 前端 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `070e6789369ba524b74e4710a7b763a20fec14fbeb4e33348b3a812f12e31cc3` |
| 前端 | `前端代码/cashier-v3/src/components/cashier/EntitlementSelectorOverlay.vue` | `ded0676cfdbab4c73cce96fd6c24d74fcd82bf1d22da774eba2389e49f331479` |
| 前端 | `前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue` | `21bee2ec20ee536fe59f1e9fd9e8b1f41739b0dc0bcb7cbb84d9f576a1681659` |
| 前端 | `前端代码/cashier-v3/src/components/reservation/ReservationEditorOverlay.vue` | `2ae524968a2b31ee1b328db1983b5978fc900625ce6f373fd2de41668ab561d0` |
| 前端 | `前端代码/cashier-v3/src/components/writeoff/EntitlementProjectSelector.vue` | `f665eb9c2a15730b7fd88ace2ed9676b9ed71bf4d91ed9818807d2aae593710b` |
| 前端 | `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `d6318fcb640ff605f2f2264758281a13171b81fa70dba4ce0a79a9ea4f9f7383` |
| 前端 | `前端代码/cashier-v3/src/views/ProjectReplacementView.vue` | `82d4e7530a3246f8931677c402d26ac1d0c9bcb047be4e11e4b32a12d2cd4a3d` |
| 前端 | `前端代码/cashier-v3/src/views/WriteoffWorkbenchView.vue` | `154a3ffcbf34215af639acf2b33c3e6cee83a877602194ff0228e00d38970595` |
| 后端 | `后端代码/app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php` | `632590c6350de7dcef287d4e985fbf13857b16eec87d5a2dcc839d14b5d433ca` |
| 后端 | `后端代码/app/services/cashier/v3/card/CashierV3CardOperationAuthorityServices.php` | `958bc74ecde5ba6f660f5e9e552db8b68370a64798e204f2f15b2eb061b777f8` |
| 后端 | `后端代码/app/services/cashier/v3/card/CashierV3CardOperationKernel.php` | `3a393df614e9958ce57a00af57d80ed8f6504921a0226cbe40dd00801b2c4885` |
| 后端 | `后端代码/app/services/cashier/v3/card/CashierV3CardUpgradeDebtPolicy.php` | `4478001c7fa92656007f66ad980895616e2b9aef3e10812190563a2ebc62eb6e` |
| 后端 | `后端代码/app/services/cashier/v3/card/CashierV3MultiCardUpgradeSettlementServices.php` | `bff11af129d063a2bf661439142d0fc0d60f6d03d5cf11b5b7af10797c550159` |
| 后端 | `后端代码/app/services/cashier/v3/cashier/CashierV3CardOriginDebtResolver.php` | `27ad07c962b2b695f1246b5ebc3f13cbae9ebb0d1cafac4c939952c9f34a918f` |
| 后端 | `后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php` | `d04aee1c27bd8eec0c65d6f55c6c8bdffdf7740cf14f32b4f023a11861c68e1a` |
| 后端 | `后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php` | `ead39f85e474b6b47e500bb093f6eaccde3285a33f2d0db31314ce09d9944ca0` |
| 后端 | `后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php` | `86ac67d3c890484e4c9297ffec120fe1a1d31864a20e5950f9e57b844ca542e5` |
| 后端 | `后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php` | `efa83faa87932cbb47ced836b97115ebd68be81e2727a31a26a6c760a95ee524` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3DebtCancellationServices.php` | `bfcdf02e078116ba5b36f28eae14cd62b7450713c8be1f725c6889c0173a3637` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3DebtRestorationServices.php` | `aade3357ec5ebf8fc55ee774536ce5ba03b055509816d820091a0339292e9d1f` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `568adfc95ab486f4e5fa2d201f9b132cbe62fcc86b492a952a18d0cf486e1007` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php` | `bff2f5579137fcc08dc2c988e0d89a09ef69f00e9be16db62eecba2458983335` |
| 后端 | `后端代码/app/services/cashier/v3/reservation/CashierV3ReservationModule.php` | `ebba5b6b8af1e03cd2eb0b0c9994cf8601e33114abb8bfab885762a4cdb9e503` |
| 后端 | `后端代码/app/services/order/store/WriteOffOrderServices.php` | `852e3db190a90f17c6a3cd732575d60cf9e62dce5d3ec1d4b8bf17c6582acda9` |
| 迁移 | `后端代码/database/upgrades/2026-10-05-收银V3欠款取消恢复/00-升级说明.md` | `0c6dbe15b66107d259da39e83cf0367f721fa42542be961242524d511731d22a` |
| 迁移 | `后端代码/database/upgrades/2026-10-05-收银V3欠款取消恢复/01-升级.sql` | `5412683a80f3a211c501138d7cfed3ba9ed5a1c252cfd81bee9e188a3dc0b461` |
| 迁移 | `后端代码/database/upgrades/2026-10-05-收银V3欠款取消恢复/03-升级后验证.sql` | `f21fa7314c39f8bd0303528010531b1eb180ff5e465bc9f8b8d6dcca01a444aa` |
| 测试 | `tests/cashier-v3/php/r14-debt-cancel-restore-mysql.php` | `ed958d737acc7cfa44b9e29aab03efcf430d6803ba57768203813a9d78baa107` |
| 测试 | `tests/cashier-v3/php/r14-shared-card-debt-limit-mysql.php` | `449c8c75f98d68c2c088a7582c80d2bd64e35ad8a4236ab9393829438ea43047` |
| 测试 | `tests/cashier-v3/php/card-operation-kernel-contract.php` | `b513c96ff1aaf4cb6f102903767b9a6a5b6f0a628d2893091967fcd609d9514e` |
| 测试 | `tests/cashier-v3/php/card-operation-upgrade-settlement-contract.php` | `b8373d22781cfb1f80347987453b952e7b17526bfa6f2e68058ac8208e70f827` |

## 自测与边界

- 本地 `ruihao` 开发库此前已执行本轮迁移，验证 SQL 异常计数均为零；本次测试在事务内操作真实本地夹具并全部回滚。没有连接或改变瑞昊线上实例。
- PHP 7.4：新旧 R14 两条 MySQL 回滚测试通过；真实部分欠款共享卡覆盖待还、取消、恢复、单卡及多卡升级门禁/逐分抵扣、项目来源次数、转让后会员详情；卡操作内核、单卡及多卡升级契约、会员详情契约通过。20 人 AI 权益批量只读结果与逐人一致（119 ms 对 750 ms）。
- 收银 Vue 3 `npm run build` 通过；18091 本地入口 HTTP 200；`git diff --check` 通过。构建只生成 `前端代码/cashier-v3/dist/`，未打 `8080` 集成包。
- 本扩展版未在浏览器执行真实升级收款；真实事务测试覆盖最终服务端拦截与金额读取，不代替产品经理的完整业务验收。
- 本轮注释覆盖升级欠款终态、金额分摊、物理次数保护、转让后原订单归属，以及原 R14 取消/恢复审计文件。新增测试断言中的“旧卡录入”公式是修正既有静态测试到现行正式事实，并未改动正式收款算法。
- 共享文件 `cashierV3ActionManifest.js`、`cashierV3Bridge.js`、`CashierV3ActionManifest.php`、`CashierV3C5MemberOrderModule.php`、`CashierV3OrderLifecycleModule.php`、`CashierV3DebtRestorationServices.php` 中同时含其他任务的“迁入旧卡退款”改动。上表是当前完整文件指纹，不代表那些 hunk 属于 R14；日后仅按本轮 hunk 暂存，不整文件夹带。其他脏文件和未跟踪文件均不属于本闭环。

## 待办：共享文件交叉验证

- [ ] 并行“迁入旧卡退款”代码稳定后，核对上述共享文件的 R14 hunk 与退款 hunk 没有覆盖、重复注册或权限/事件契约冲突；特别验证“旧卡已退款且关联欠款曾取消”时恢复欠款被服务端拒绝，不误触发本轮卡升级抵扣。
- [ ] 重跑 R14 取消/恢复、共享卡欠款限制、单卡/多卡升级及退款侧相关回归；重新计算本表文件 SHA-256 和补丁指纹。任何共享文件变化均使当前整文件固定版本失效，须说明受影响范围并请产品经理补验。
- [ ] 获得本扩展版本明确验收后，只暂存 R14 的 hunk 与本轮新增文件；逐项检查暂存 diff，绝不把迁入旧卡退款或其他工作区改动夹带进 R14 commit。

## 2026-10-05 发布候选补记

- 产品经理已对本轮本地测试结果明确表示无问题，并授权本地 commit、仅部署瑞昊及线上验证通过后 push。上文“待验收”是最初冻结时的状态；本节记录后续验收决定。
- 本轮暂存快照的 Git tree（加入本补记前）为 `f16f61d142e73aa5ec88435e5c1c49fc50a7e9c6`，暂存补丁 SHA-256 为 `24c5db59b808348fa56104dbe164f32382f9c1803cc8171aa1e7ef78f864f87f`。前文整文件 SHA-256 是开发工作区快照，包含并行退款任务的改动，不作为发布包校验值。
- 共享文件按 R14 hunk 暂存；新增恢复服务在暂存版中排除了并行退款任务的防重背债分支，工作区原文件保持不变。暂存补丁不含旧卡退款命令、弹窗或服务端注册。该交叉业务约束保留在上面的待办，待退款闭环合并时再验证。
- 从暂存 Git tree 归档构建收银 Vue 3 前端成功。PHP 7.4 对暂存的 25 个 PHP 文件语法检查全部通过；本地 MySQL 事务回滚测试 `r14-debt-cancel-restore-mysql.php`、`r14-shared-card-debt-limit-mysql.php` 和两条历史商品名称测试均通过。正式部署仍须先完成瑞昊生产库迁移备份与单独授权。
