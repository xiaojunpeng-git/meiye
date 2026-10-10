# R40 消费订单优惠券与用券前金额

## 固定版本

- 基线 HEAD：`082c670cd497262cb5a13b3f50625b5d681d693e`
- 本闭环已跟踪源码补丁 SHA-256：`17d0c6f3afecfb8287696ba425818a52536bfe52bf44d0ef68658314a6bd1e1a`
- 前端源码：`前端代码/cashier-v3/src/views/OrderCenterView.vue` (`9db5a1ae8a5de27f599504d3efef30ea0622f8758ed72599f1e7355e1e6a7a96`)
- 后端源码：`后端代码/app/services/cashier/v3/order/CashierV3SalesOrderQueryServices.php` (`89b268b74b1b6564715154eb285d39195ee7cda7e74114455c7310d0a829a7ec`)、`CashierV3OrderCenterUnifiedQueryContract.php` (`36cf000b64d706367ac1ed9d4bf8bad2b6ec310a09bf09e22797180ddac4dd9c`)、`AbstractCashierV3OrderCenterUnifiedQueryProvider.php` (`5d05cf24c99b869cb0e61f34d492252080381d069fb98aba12a03c753f71a8f9`)
- 自动化测试：`tests/cashier-v3/php/order-center-sales-coupon-amount-contract.php` (`8500c0253a187dab38bb52f4c02949eccdc297a6e396a6faefc3d526a27c982a`)
- 数据库迁移、工程脚本：无。生成物：本地 Vite `dist/`，不作为源码交付。

## 数据架构自检

- 权威源是成功结账后的 V3 正式销售订单明细及冻结的 `coupon_discount_cents`；事实粒度、业务日、发生/结算/记录时间均不变。购买明细“金额”仅作为用券前展示投影，等于正式成交金额加该明细用券金额；整单“优惠券”是展示的正式购买明细用券金额之和。示例：¥11,880 用券前、¥1,000 优惠券、¥10,880 应收。
- 查询、筛选、导出复用统一查询契约和后端权限范围。订单号、明细号、会员和门店仍取结账快照。权益行保留独立核销价值，旧记录没有权威金额时显示空值而不猜测。
- 不修改销售、优惠券消费、收款、欠款、退款或作废事实，不新增事件和幂等写入，不做历史回填。每张订单遍历已有明细求和，无新数据库查询；对账条件是各购买明细的用券前金额之和减优惠券金额等于其成交金额之和。

## 自测与限制

- `order-center-sales-coupon-amount-contract.php`：5/5 通过，覆盖改价与用券同时存在、无券、字段顺序、列表与导出同口径、冻结来源。
- `order-center-sales-readonly.php`：50/50 通过；前端 `npm run build` 通过；PHP lint 和 `git diff --check` 通过。
- `order-center-unified-export-contract.php`：35/38 通过。3 条失败是日期默认值与列表标题的旧静态字符串断言；本次未修改这些逻辑，不能记为整套通过。
- 本地 `18091` Vite 已返回新增前端字段，`mohe-platform-app` 挂载后端也含新增字段。未在登录会话中执行真人浏览器验收；未写库、未做真实支付、未提交、未部署或推送。待产品经理对固定版本确认验收。
- 本次更新业务注释的文件：`OrderCenterView.vue`、`CashierV3SalesOrderQueryServices.php`、`AbstractCashierV3OrderCenterUnifiedQueryProvider.php`。
