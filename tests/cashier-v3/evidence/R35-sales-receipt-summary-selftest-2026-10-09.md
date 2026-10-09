# R35 销售小票汇总调整自测

- 基线 HEAD：`b9547566`。
- 前端源码：`前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js`，SHA-256 `50b008d1876b3618553168a5da9cdf20a63aaac05621de0ccd71dfcec4fa1a4d`。
- 自动化测试：`tests/cashier-v3/js/sales-order-receipt-print-contract.mjs`，SHA-256 `01852acf74535b0e874d5992fdc6856ed1331cc0c8638da8fcd5e999f25981b9`。
- 两个文件的补丁指纹 SHA-256：`a1830bc70ad7c2d853b1dde53d0862db868d9640c92fd404331051f935776221`。
- 后端源码、数据库迁移、工程脚本：无。构建生成物 `前端代码/cashier-v3/dist/` 不纳入源码交付。

## 范围

销售小票不再显示原价合计和优惠合计；保留应付金额，欠款大于 0 时紧随其后显示欠款合计。收银成功页的小票适配器从本次结账快照携带冻结欠款金额；订单中心小票继续读取订单详情的 `amountSummary.debtAmount`。无欠款不显示欠款行。

## 数据架构自检

仅改变已完成结账快照的打印投影，不创建或修改订单、收款、欠款和经营事实；不改变事实粒度、业务日期、真实操作时间、成功时点、幂等或冲销。欠款只从既有快照读取，不由应付、实收差额推算；订单中心沿用后端当前权限下的订单详情，不扩展数据范围。历史小票按现有订单详情重印时采用同一模板；没有历史数据回填、索引或对账变化。

## 验证

- `node tests/cashier-v3/js/sales-order-receipt-print-contract.mjs`：18 项通过；覆盖欠款正数、零欠款、结账快照适配、显示顺序与旧小票打印行为。
- `npm run build`（`前端代码/cashier-v3/`）：通过。
- `git diff --check`：通过。
- 浏览器临时只读标签进入登录页；未借用当前用户结账现场，因此未声称登录后的真实页面视觉验收。待产品经理对本固定版本确认。

## 注释

更新 `salesOrderReceiptPrint.js`：解释成功结账快照的欠款搬运边界，明确不得由收款反推欠款。
