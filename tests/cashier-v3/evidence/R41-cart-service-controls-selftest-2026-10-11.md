# R41 收银购物车项目行控件换位自测

- 基线 HEAD：`217200a1`。
- 范围：所有购物车明细在标题行右侧统一呈现数量、明细备注、删除；仅项目行在明细行右侧呈现服务对象三选项。数量大于 1 时，在数量右侧用普通字号显示合计，单价与数量随新增合计向左移动，备注和删除保持右对齐。购物车行金额统一显示整数元且不带千位逗号。权益行左侧始终展示原购买平均单价，右侧合计展示本次实际分摊金额。
- 数据架构自检：数量、备注、删除及服务对象仍调用既有命令；结账唯一快照与权威金额不变。合计仅用于展示：本地销售行复用既有草稿合计函数，权益行读取已分摊金额，避免权益金额重复乘数量。权益单价仅按原购买金额和原购买次数截取整数元，不替代末次尾差；不修改事务、幂等、冲销、权限、报表或迁移。
- 注释：新增并更新 `CartLineRightControls.vue` 的职责、草稿金额与权益尾差边界说明；`CashierWorkbenchView.vue` 补充无分组显示、权益单价与合计边界注释；更新 `base.css` 控件布局说明。
- 自测：`npm run build` 通过（仅有既有大 chunk 提示）；`node tests/cashier-v3/js/cart-line-detail-remark-contract.mjs`、`node tests/cashier-v3/js/cart-line-quantity-total-contract.mjs`、`node tests/cashier-v3/js/entitlement-experience-checkout-snapshot.mjs`（62 项）、`node tests/cashier-v3/js/local-checkout-entitlement-snapshot-contract.mjs`、`php tests/cashier-v3/php/entitlement-unit-price-contract.php`（12 项及末次尾差）均通过。PHP 仅有既有参数可空类型弃用提示；PHP 测试原先按字面数 `unitPrice` 次数的断言已改为检查项目和卡源两处实际调用。
- 页面验证：本地 `18091` 中，销售卡项 `东方熏蒸` 数量从 1 到 2，行金额保持 `¥3180`，合计显示 `¥6360`，数量及金额均向左移动约 79px，备注右边缘保持不变。权益项目 `幸福霜` 数量从 1 到 2，左侧原购买平均单价保持 `¥298`，右侧合计显示 `¥596`，应收 `¥6,358` 不变。原有五项草稿经用户批准先挂单；测试后恢复数量为 1 再挂单。恢复挂单未消耗原挂单，故经用户当场批准删除较早的重复项，列表最终仅保留 2026-10-11 03:39 的一条，五项、`¥6,358`，未提交结账。真实收款预览受“请先选择手艺人”校验阻挡，未任意指定人员，未验证最终交易。更早的一次 Vite 热更新重载过浏览器本地草稿，不作为持久化验证结果。
- 前端源码：`前端代码/cashier-v3/src/components/cashier/CartLineRightControls.vue`、`前端代码/cashier-v3/src/views/CashierWorkbenchView.vue`、`前端代码/cashier-v3/src/styles/base.css`。
- 自动化测试：`tests/cashier-v3/js/cart-line-detail-remark-contract.mjs`、`tests/cashier-v3/js/cart-line-quantity-total-contract.mjs`、`tests/cashier-v3/php/entitlement-unit-price-contract.php`。
- 后端源码、数据库迁移、工程脚本：无。
- 生成物：本地 Vite `dist/`，不纳入源码提交。
- 验收证据：本文件；开发与自测完成，待产品经理对当前固定文件版本确认验收，未提交、部署或推送。

## 固定文件指纹（SHA-256）

```text
12fd72bd0d53d64021f7d2048f5a7cff43877f3ab12d609ea605cd09d33b862a  前端代码/cashier-v3/src/components/cashier/CartLineRightControls.vue
dccf981ace46f77838328cc7ec0a0803283fe0f745350aecfa91249fe925d8d3  前端代码/cashier-v3/src/views/CashierWorkbenchView.vue
2f673fbc99f4bc5b1c034ab1116967cb119a2cdc64564071bc38c5bb58397d84  前端代码/cashier-v3/src/styles/base.css
c80cb6616d5d8c2275c7399bafb1bd3acce6882c6f55fe235080933c0027f2e4  tests/cashier-v3/js/cart-line-detail-remark-contract.mjs
6cc9b931aac842cf0e8db70ceaed0550e30320f3b100dd579b0ea50bc04063ec  tests/cashier-v3/js/cart-line-quantity-total-contract.mjs
3c5d2917ed5f6f2573898b9d37fd6d2210962a236018497d7c145d1563e9e523  tests/cashier-v3/php/entitlement-unit-price-contract.php
```

跟踪文件补丁指纹（SHA-256）：`617760a2c3b54e42791a05a601d4cd2fd944f259b0e748c07e469c865eb855e1`；新增组件与测试以上述文件指纹固定。
