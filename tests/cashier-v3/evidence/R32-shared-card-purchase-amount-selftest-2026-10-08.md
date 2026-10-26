# R32 共享次数卡购买金额计价：本地自测固定版本

- 状态：本地开发与自测通过；待产品经理对本版本确认验收。未提交、未部署、未推送，未写瑞昊生产数据。
- 基线 HEAD：`c5ef6084c7258c1e57d99fd5c4f875adc5de1d16`。
- 已跟踪改动补丁 SHA-256：`22bbcb49ce3001b89cfa56bb68c780d0896ab1065f53befcf36394050d51df82`；以下各文件 SHA-256 固定完整文件版本，不包括工作区其他脏文件。

| 分组 | 文件 | SHA-256 |
| --- | --- | --- |
| 前端源码 | `前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` | `eb75fb86ea497a56d474d423f647be6bc9b06dad728c533f15695637bd526fdb` |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3SharedCardPurchaseAmount.php` | `63a9d3bcc8909e17dad82837a6468bc1fdbeac7e6ee9057d952e9796a7397620` |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php` | `e4b8490b6ed7818dc8a6f3066ab313ad2af30b0e6f6252a776d00bd97472c90a` |
| 后端源码 | `后端代码/app/services/cashier/v3/cashier/CashierV3CashierMemberSummaryServices.php` | `1b3c1df3611e562d4747ab061e621d6544b337536f41b950608b6361b70905a6` |
| 后端源码 | `后端代码/app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php` | `7ad6f19c6c482b0cd5426a4de77102b997d391290fe95525b29cf49576a701e9` |
| 自动化测试 | `tests/cashier-v3/php/r32-shared-purchase-amount.php` | `16373ce1ede465179c48e6f70f53da89f75b2fb5138122f27198150ac6e54703` |
| 自动化测试 | `tests/cashier-v3/php/r32-shared-purchase-readonly-mysql.php` | `16841544c9fb4bbcba226b3229ae99179666175738579517a88e23bda5364b89` |
| 自动化测试 | `tests/cashier-v3/js/r32-shared-purchase-amount.mjs` | `710a2308c953079593eca65c9983daafb50f7a069268da0281a48c7bfe403e5e` |

- 数据库迁移、工程脚本：无。生成物：`前端代码/cashier-v3/dist/` 为本地构建输出，不作为源码提交。验收证据：本文件。
- 口径：正式任选次数卡使用整卡实际购买金额 / 共享购买次数；前期每次取整元，原购买金额尾差归最后一次真实服务。退款扣减的是未来次数，不推进服务尾差。替换/升级的独立项目保持其独立金额快照。项目销售分摊事实仍用于销售追溯，不作为共享服务单价。
- 注释：本次在上述四个修改过的业务源码文件及新增金额类中补充了共享池、退款、权威重算和独立项目边界说明；测试文件注明只读/纯公式边界。

## 验证

- `php tests/cashier-v3/php/r32-shared-purchase-amount.php`：10 项通过，覆盖 ¥2000/30 次前 29 次 ¥66、末次 ¥86、退款移除次数、独立替换项目及非整元成交阻断。
- `node tests/cashier-v3/js/r32-shared-purchase-amount.mjs`：通过，覆盖同卡跨项目共用次数轴与末次尾差。
- 本地 `ruihao` 只读库 `r32-shared-purchase-readonly-mysql.php`：真实共享卡 holder `2068853` 的选择器、最终锁定权威及会员摘要读通；整卡金额均为 ¥2000。
- 本地 `ruihao` 只读库 `r17-card-actual-sale-final-settlement-mysql.php`：普通卡有效欠款 52 例、取消欠款 4 例通过。
- 本地 `ruihao` 事务回滚库 `r14-shared-card-debt-limit-mysql.php`：共享卡欠款、取消、恢复及升级回归 21 项通过，测试写入全部回滚。
- `npm run build`（`前端代码/cashier-v3/`）、相关 PHP 语法检查、`git diff --check`：通过。构建仍有既有大包体积提示。

## 未完成与上线门禁

- 瑞昊历史服务 `FW100801710` 已按错误基数记录 ¥28.22；按整卡 ¥2000/30 次，首笔应为 ¥66，差额 ¥37.78。历史服务、核销、消费及手艺人业绩事实不会因代码变更自动更新。须先做只读完整影响清单、独立备份、可回滚修复方案及对账，另获生产数据修复授权；否则不能将本代码视为线上闭环。
- 本版本尚未取得产品经理固定版本验收，也未取得本轮部署、生产库修改或 push 授权。其他工作区脏文件不属于 R32。
