# 第 6 轮会员购买优惠券与积分抵扣固定版本

- 固定日期：2026-10-20
- 基线 HEAD：`e845d7805f379708d4d5157a388c3f39e50f2dc4`
- 业务文件内容清单指纹：`2954e46ce4ef6536f799ce4a1ed38228a34dc0c5c8a33cab4f5ad4418bfa6a5d`
- 状态：开发与本地自测完成，待产品经理确认验收
- 远程状态：未部署、未改 008 数据库、未配置 008 富友、未 push

## 范围

1. 会员购买页恢复优惠券选择和积分抵扣，充值页保持原样且不允许使用优惠券、积分或余额充值。
2. 明细券、整单门槛券均复用门店收银 V3 的锁定、金额校验、核销和作废退券能力。
3. 积分按整元规则降低商品成交金额，逐商品记录抵扣金额，并在成功结算后绑定正式销售单明细 ID；本轮不开放门店端积分操作入口。
4. 待支付订单占用优惠券和积分，取消后释放；支付成功回调再次复核可用积分；整单作废退回积分并更新审计状态。
5. 修复 `MC-*` 订单号详情、继续支付和取消路由无法命中的问题。

## 固定文件

- `前端代码/uniapp/pages/goods/member_checkout/index.vue` `56bc943615193db28ec7b819efbc3eec327b35c2db92e297d0fe582c94687b64`
- `后端代码/route/api.php` `6a5168e434618d0970d245cd66329319e9dff909c22121708af0499f8782cb12`
- `后端代码/app/controller/api/v1/order/MemberCommerce.php` `4efc10cf523f1c2917afee6c2f78398cf8ffb05d0934bc248aee11bea7341ddb`
- `后端代码/app/services/member/MemberCommerceServices.php` `512092a4f572a120a95790bd860585c489bd5a222a4caa8ed3afaa0317e1149c`
- `后端代码/app/services/member/MemberCommerceBenefitsServices.php` `113f6cac55e900b49e8be4a40806e47ef6c776734aa3b782913b892e0f03fc2b`
- `后端代码/app/services/cashier/v3/order/CashierV3SalesOrderReversalServices.php` `17c106145e83d52e3a34ad833e22785bd1e3c469f5627c5f6583bbbe89feff1e`
- `后端代码/database/upgrades/2026-10-20-会员结账优惠券积分/01-schema.sql` `d07d96817539ad5bad20097bd390ecb233b31381bb0d9ae5fe350ced3f088add`
- `tests/member-checkout/round6-benefits.php` `ee7ab6534bb072cd4690e0d29717dee4a6a3f23f5787dbb9e4589749f1a58587`
- `tests/member-checkout/round6-benefits-static.cjs` `069a620e833b8bd744ed63eeecc8f0a5696507f033381a261f2f7effa6f350b9`

## 自测

- PHP 语法、`git diff --check`：通过。
- MySQL 5.7 回滚集成：明细券、整单门槛券、积分整元抵扣、逐商品绑定、富友成功回调、取消释放、整单作废、充值隔离：通过。
- 会员购买边界回归 8 项：通过。
- 会员结账 Vue 2 模板、数量、销售人多选、主题按钮、优惠券抽屉与积分开关：通过。
- 富友支付静态契约与 PHP 兼容检查：通过；仅有既有 PHP 8 nullable 弃用提示，与本轮无关。
- 本地 H5 `http://127.0.0.1:8081/pages/goods/member_checkout/index?product_id=625196`：HTTP 200，商品、优惠券、积分、销售人和支付方式正常渲染。
- `MC-TEST` 详情和支付路由：均到达鉴权层并返回“请登录”，不再 404。

## 发布前置

产品经理验收后先精准本地提交。若随后明确授权部署 008，则先备份并执行新表迁移、部署同一固定代码，再按已核实的旧渼约富友配置写入 008 独立配置并验证；全程不输出密钥明文，不发起真实扣款。
