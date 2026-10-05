# R14 欠款取消、恢复及详情历史：固定自测版本

- 基线 HEAD：`ad686ca774fdd31a96bf6d92257d265c6b8a8d14`，分支 `release/store-cashier-c14-main`。
- 已批准业务口径：1000 元 10 次卡欠 450 元时可用 5 次；取消及恢复但未补交时仍可用 5 次，补交后才解除限制。保留原始次数与已用记录。
- 当前状态：开发与自测完成，**待产品经理对本固定版本确认验收**。未 commit、未部署、未 push；仅本地 `ruihao` 开发库已执行迁移。
- 已跟踪文件补丁 SHA-256：`1ef60c5a9817f2f7aa2e55ad7994431e4f0a353deacb3d15051089c37e8ab97e`。以下逐文件 SHA-256 同时覆盖新增文件；本证据文件不纳入自身指纹。

## 闭环文件与 SHA-256

| 分组 | 文件（相对于 `美容源码/`） | SHA-256 |
| --- | --- | --- |
| 前端 | `前端代码/cashier-v3/src/components/order/BusinessRecordDetailOverlay.vue` | `d2fb1f8af46aa3c8ef21001be4f320c50f453fe66f62ed46ae9337710facc558` |
| 前端 | `前端代码/cashier-v3/src/services/cashierV3ActionManifest.js` | `4144073f70a899c0aeccdaeed630d938bdd332e3d28cf795fa285ac2d9b82db9` |
| 前端 | `前端代码/cashier-v3/src/services/cashierV3Bridge.js` | `686c552341491ce80ae2989caab11923b1dc8256f8ae620e12f9b6540de9aff7` |
| 前端 | `前端代码/cashier-v3/src/views/OrderCenterView.vue` | `070e6789369ba524b74e4710a7b763a20fec14fbeb4e33348b3a812f12e31cc3` |
| 后端 | `后端代码/app/services/cashier/v3/card/CashierV3CardRuleEntitlementAuthorityServices.php` | `632590c6350de7dcef287d4e985fbf13857b16eec87d5a2dcc839d14b5d433ca` |
| 后端 | `后端代码/app/services/cashier/v3/cashier/CashierV3CardOriginDebtResolver.php` | `27ad07c962b2b695f1246b5ebc3f13cbae9ebb0d1cafac4c939952c9f34a918f` |
| 后端 | `后端代码/app/services/cashier/v3/manifest/CashierV3ActionManifest.php` | `c43c8a257cda031084d440d4268d17b16e3569824aeb595c0b3881d33b36c498` |
| 后端 | `后端代码/app/services/cashier/v3/manifest/CashierV3C5MemberOrderModule.php` | `bba1395913bd21aff842eae20f4edbb206928ae09e77a8923fc0fd4ea4ced0ff` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3DebtCancellationServices.php` | `bfcdf02e078116ba5b36f28eae14cd62b7450713c8be1f725c6889c0173a3637` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3DebtRestorationServices.php` | `abbfcb1e5a00859376f4c7fd9df0e4bba9acd0c73a8272b167824f6d80ea8d5c` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3OrderCenterRecordQueryServices.php` | `568adfc95ab486f4e5fa2d201f9b132cbe62fcc86b492a952a18d0cf486e1007` |
| 后端 | `后端代码/app/services/cashier/v3/order/CashierV3OrderLifecycleModule.php` | `46465379fadc7fa8d3409a71bcb574c4183d24ffb7793695c20c3aee3bf4d8b3` |
| 后端 | `后端代码/app/services/order/store/WriteOffOrderServices.php` | `852e3db190a90f17c6a3cd732575d60cf9e62dce5d3ec1d4b8bf17c6582acda9` |
| 迁移 | `后端代码/database/upgrades/2026-10-05-收银V3欠款取消恢复/00-升级说明.md` | `0c6dbe15b66107d259da39e83cf0367f721fa42542be961242524d511731d22a` |
| 迁移 | `后端代码/database/upgrades/2026-10-05-收银V3欠款取消恢复/01-升级.sql` | `5412683a80f3a211c501138d7cfed3ba9ed5a1c252cfd81bee9e188a3dc0b461` |
| 迁移 | `后端代码/database/upgrades/2026-10-05-收银V3欠款取消恢复/03-升级后验证.sql` | `f21fa7314c39f8bd0303528010531b1eb180ff5e465bc9f8b8d6dcca01a444aa` |
| 测试 | `tests/cashier-v3/php/r14-debt-cancel-restore-mysql.php` | `ed958d737acc7cfa44b9e29aab03efcf430d6803ba57768203813a9d78baa107` |
| 测试 | `tests/cashier-v3/php/r14-shared-card-debt-limit-mysql.php` | `0b2ea948ff6dde9296354beb798be465510049534586eb928358af9e81f2aaec` |

## 自测与限制

- 本地 `ruihao` 迁移两条 DDL 执行通过；03 的四项异常计数均为 0。没有连接或修改瑞昊线上实例。
- PHP 7.4 本地容器：R14 取消/恢复 15 项、R14 真实共享卡 7 项、R49 取消欠款 19 项、R50 取消后权益 15 项通过。R14 测试事务全部回滚；共享卡还验证最终锁定核销拒绝超限。
- 旧 R50 共享 30 次特定取消卡测试：5 项纯公式通过，随后因本地缺少指定夹具停止；旧 R50 活跃欠款实际分摊测试：本地缺少指定夹具，未进入业务断言。不能将这两条标为通过。
- 收银 Vue 3 `npm run build` 通过，仅有原有大包体积提示；`git diff --check` 通过。浏览器本地详情已显示“变更记录”空状态；写入流程使用事务回滚测试验证，未在浏览器执行真实业务写入。
- 注释已更新：`BusinessRecordDetailOverlay.vue`、`OrderCenterView.vue`、`CashierV3CardRuleEntitlementAuthorityServices.php`、`CashierV3CardOriginDebtResolver.php`、`CashierV3DebtCancellationServices.php`、`CashierV3DebtRestorationServices.php`、`CashierV3OrderCenterRecordQueryServices.php`、`CashierV3OrderLifecycleModule.php`、`WriteOffOrderServices.php`，说明了历史事实、权限、幂等、同秒事件版本及可用次数边界。
- 生成物：本地 `前端代码/cashier-v3/dist/` 为构建产物，不是开发源码，不纳入提交边界。工作区其他既有脏改动完全不属于本闭环。
