# R40 会员优惠券详情本地自测

## 固定版本

- 基线 HEAD：`785b2a841916e3f72869968b5255e4707002c461`
- 已跟踪源码补丁 SHA-256：`3ff0d292895030a208d3d00b87fbad33800e71798fcff4b5d2362f525b63d808`
- 前端源码：`前端代码/cashier-v3/src/components/member/MemberDetailOverlay.vue` (`270accb487d768adb360f5594bb648b5d0bf677ed070211a53f580ab30bc4f37`)
- 前端源码：`前端代码/cashier-v3/src/layouts/CashierShell.vue` (`fbcf55da6955f3dfb2efc5ec11b089334d564748a86730c7c7a086bfce0f5d57`)
- 后端源码：`后端代码/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php` (`3d375a5d023480a0b4197c1b764db3824c06b8246b3953e34367ce13d54ffdb8`)
- 自动化测试：`tests/cashier-v3/js/member-coupon-detail-frontend-contract.mjs` (`f596fd3c9d315b37f06e54204d7d7dd72c98233f663816e5aa53e63482ff7ec8`)
- 自动化测试：`tests/cashier-v3/php/member-coupon-detail-mysql-integration.php` (`8b3bda81f692deb04de5eecf64f8e3b1745c5d6befbf09f5d559b53d19aaccaf`)
- 数据库迁移、工程脚本：无。生成物：cashier-v3 `dist/`（忽略，不作为源码）。

## 业务核对

- 本地 `ruihao` 库中，赠送单 `ZS26101000031` 对应会员的两张券实例 `16833`、`16834` 均为有效；此前会员详情接口没有返回 `coupons` 字段，因而页面显示为空。
- 会员详情优惠券读取 `store_coupon_user` 当前券实例；赠送事实仅用于核对发放，不反推当前可用状态。有效券不受日期筛选，全部历史可按发放日期查询；已作废券仅出现在全部历史。
- 权益明细内原优惠券表格已移至独立页签，紧挨销售订单左侧。页签查询保持按会员授权读取，未增加写操作。

## 自测

- `php -l`（后端服务与新增集成测试）：通过。
- `docker exec -i mohe-platform-app php < tests/cashier-v3/php/member-coupon-detail-mysql-integration.php`：5/5 通过；只读本地数据库。
- `node tests/cashier-v3/js/member-coupon-detail-frontend-contract.mjs`：通过。
- `node tests/cashier-v3/js/member-profile-archive-frontend-contract.mjs`：通过。
- `npm run build`（cashier-v3）：通过；现有大 chunk 提示仍在。
- `git diff --check`：通过。`18091` Vite 热更新页 HTTP 200，后端 `18092` 容器读取到新服务源码。
- 现有 `member-detail-v2-contract.php` 为 12 通过、2 失败；失败断言仍要求旧的无 `sf.` 别名 SQL 文本，在本轮基线 HEAD 中已不匹配，不是优惠券变更引起。

## 注释与限制

- 更新注释：`MemberDetailOverlay.vue`、`CashierShell.vue`；新增职责与券实例权威口径注释：`CashierV3MemberDetailQueryServices.php`，新增测试含回归目的注释。
- 当前浏览器处于登录页，尚未用产品经理账号完成会员详情页面的真人验收。开发与自测完成，待产品经理确认固定版本；未 commit、部署或 push。
