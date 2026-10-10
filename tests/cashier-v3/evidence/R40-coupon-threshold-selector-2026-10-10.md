# R40 收银优惠券未达门槛展示自测

## 固定版本

- 基线 HEAD：`082c670cd497262cb5a13b3f50625b5d681d693e`
- 数量变更、优惠券与结账显示闭环补丁 SHA-256：`5b79acb9f49a497bc839e154333c7b2beea8e15075ff6da692a3ade89bd4a4e9`
- 前端源码：`前端代码/cashier-v3/src/components/cashier/CashierCouponSelectorOverlay.vue` (`7e2e7ae124571f4b8ea785b046eae8c005225fd459ae7712d901651689805c96`)
- 前端源码：`前端代码/cashier-v3/src/views/CashierWorkbenchView.vue` (`885ef93a49c2f20c8e25f8196bb4147fd2354e8bd5ffec14057169acca661db1`)
- 前端源码：`前端代码/cashier-v3/src/components/cashier/CashierCheckoutOverlay.vue` (`5a800129e5d68dc3b01473c8661db9b6b5a5156600961a65c669964bb03661ae`)
- 后端源码：`后端代码/app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php` (`11d935abf0f4462f2de3502f64c923810f079f862f1fdd1841e336b2f79a28c4`)
- 自动化测试：`tests/cashier-v3/js/r15-line-coupon-frontend-contract.mjs` (`b645c0f5d04e82a6bb402ccefdee545d51d5c5f671f07652b94cee8ef722efb1`)
- 自动化测试：`tests/cashier-v3/js/cashier-line-debt-frontend-contract.mjs` (`54668f45736ea9e9543db2f319919643f1fd33b525e9ba30952a4c0556d2bea7`)
- 自动化测试：`tests/cashier-v3/js/local-draft-final-confirmation-contract.mjs` (`b9e68db0da89f3427bea6e55b1c05314d1d10d32c330da5126740dda5009eebe`)
- 自动化测试：`tests/cashier-v3/js/entitlement-experience-checkout-snapshot.mjs` (`9e93e19f462dbeacc1d45b8590cc7d4822ce995c9137218287c01457f2a4fef4`)
- 自动化测试：`tests/cashier-v3/php/r40-coupon-threshold-selector-mysql-integration.php` (`9d2d0626482d94a591b79696372a76bf79a4fffc6e5951ad75fd5fd28b2e844b`)
- 数据库迁移、工程脚本：无。生成物：cashier-v3 `dist/`（忽略，不作为源码）。

## 数据架构自检

- 权威业务源：会员持有券实例 `store_coupon_user`，发行规则 `store_coupon_issue`；每张券实例是最小权益粒度。接口沿用当前会员、门店、工作台身份校验。
- 本次只扩充选择器的只读投影，不新增发券、使用、收款或经营事实，不改变业务日、操作时间、结算时间、记录时间或历史快照。
- 可用券与未达门槛券由同一次权威查询分类；跨门店不适用、过期、已用、作废及其他行已占用的券仍被排除。写命令在事务内重新锁定并只读取可用券；客户端禁用提示券，不提供绕过入口。
- 本地商品行选券与结账都按单价乘数量计算整行金额；一张券只在整行扣一次。改数量时旧券先失效，再按新整行金额从后端重新核验同一张券；仍可用则自动恢复并采用后端重算券额，不可用或查询失败才清除并提示。核验在途不允许进入结账。多旧卡升级已计入的券额不二次扣减。服务端最终收款仍锁定券实例并重新校验门槛。幂等、冲销、报表和导出逻辑不变。
- 结账预览现在冻结扣券后的整行金额，右侧“应收”与收款方式使用同一金额；已经打开、缺少冻结金额的本地预览按整行补扣一次券。此前右侧单价乘数量的回退计算漏扣券，导致显示应收比默认现金金额多 ¥1,000。该修复不改后端券规则或最终交易。

## 自测

- 本地 `ruihao` 数据：赠送单 `ZS26101000031` 的两张有效券门槛各为 ¥10,000；截图单价 ¥3,960、数量 4，整行 ¥15,840，使用一张 ¥1,000 券后应付 ¥14,840。数量 1 时差额仍为 ¥6,040。
- `php -l`（后端服务和新增测试）：通过。
- `docker exec -i mohe-platform-app php < tests/cashier-v3/php/r40-coupon-threshold-selector-mysql-integration.php`：7/7 通过，仅读本地库；¥3,960 × 3 和 × 4 都满足门槛。
- `node tests/cashier-v3/js/r15-line-coupon-frontend-contract.mjs`：19/19 通过，包含实际运行草稿选券、改数量后重选，以及降到门槛以下清券。
- `cashier-line-debt-frontend-contract.mjs`：7/7 通过；`entitlement-experience-checkout-snapshot.mjs`：62/62 通过，覆盖三件商品及截图四件商品 ¥12,720 → ¥11,720 的预览、右侧应收、旧预览回退、支付校验与最终快照；`checkout-coupon-settlement-contract.php`：通过。
- `local-draft-final-confirmation-contract.mjs` 在现有 `openCheckout` 结构断言处失败，属于本次修改前既存结构差异；本次涉及的快照行金额断言已同步，未将该合同测试计为通过。
- `npm run build`（cashier-v3）：通过；现有大 chunk 提示仍在。
- `git diff --check`：通过。`18091` 热更新页 HTTP 200，`18092` 后端容器读取到新增投影代码。
- 修改的业务代码注释：`CashierCouponSelectorOverlay.vue`、`CashierWorkbenchView.vue`、`CashierCheckoutOverlay.vue`、`CashierV3CashierWorkspaceServices.php`；新增测试注释说明只读夹具及回归边界。
- 真人页面及产品经理验收待确认；未 commit、部署或 push。
