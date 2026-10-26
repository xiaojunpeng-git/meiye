# R12 打印横向缩放回归修正

## 用户反馈与判断

用户在长小票修复后提供实体照片：文字明显偏大，右侧日期、金额被截。前一版未改 CSS 字号数值，但解除根容器宽度、最小宽度及横向 overflow 属于额外改变，会影响原生宿主的横向适配条件；这是当前优先排除的回归因素，尚无实体对比证据证明唯一根因。

## 本次修正

- 撤回前一未验收补丁对根容器 `width`、`min-width`、`overflow-x` 的覆盖，只保留 `height`、`min-height`、`max-height`、`overflow-y` 的纵向解除。
- 不通过猜测倍率、缩小字体或改驱动补偿。字号、纸宽、63×210mm 页长、签字间距及撕纸区保持既有值。
- 前端源码：`前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js`，同步注释说明仅解除纵向约束及保护原生横向缩放边界。
- 自动化测试：`tests/cashier-v3/js/sales-order-receipt-print-contract.mjs`，增加原生 print 调用期间保留横向值与 important 优先级、两套打印 CSS 禁止覆盖横向属性的断言。
- 夹具沿用 `tests/cashier-v3/js/receipt-pagination-fixture.mjs`；后端、迁移、工程发布脚本：无。
- 生成物与证据：`R12-long-receipt-2026-10-11/mixed-width-restored.pdf`、对应两页 PNG、`mixed-width-restored-browser.png`。

## 验证

1. 小票契约 21 PASS、0 FAIL；作废/小票恢复两组 PASS；本次文件 diff check 通过。
2. 实际浏览器复用源码和收银全局 CSS，打印准备前后 html/body 的 min-width 均为 1120px；overflow-x 分别保持 visible/auto。解除高度后两个根容器均撑至 1238.22px，不再固定屏幕高度。
3. CDP PDF 验证：2 页，三行签字完整，页面仍约 63×210mm；pdftoppm 渲染检查两页，内容完整。
4. 未发送实体打印任务，未改驱动或系统设置，未部署、commit 或 push；测试服务及临时标签页已清理。

## 固定版本与待验收

本记录替代 `R12-long-receipt-selftest-2026-10-11.md` 的待验收源码指纹；原记录保留为修正前的历史自测事实。

- 基线 HEAD：`be821109147b046734c27fcf69472af44289e1b1`。
- 前端源码 SHA-256：`dad82ba5f878d1c12c5e16b8ffb6ae7b502ca699310c7ddcbc3cb30d525cd5ae`。
- 契约测试 SHA-256：`1d8f3eb6d1bf8b20c746eba9956c8a7815e80f60345aa3a49069bd7cbe9eb65c`。
- 夹具 SHA-256：`04333f54f6144ca5a898e1a4644e1fe5eabaee1d4bb5e19b4310dc3e7e4bc6f3`。
- 已跟踪闭环补丁 SHA-256：`097128e2e977d157d853695d663ef29459b7457476565ab647781a26d3786b79`；新增夹具单独以上述指纹标识。

开发与本地自测完成，待产品经理验收。需同一浏览器、纸张及打印设置复打用户这笔混合订单，核对实体字号和右侧日期/金额，以及三行签字是否完整；不能用 PDF 自测替代这项现场验证。
