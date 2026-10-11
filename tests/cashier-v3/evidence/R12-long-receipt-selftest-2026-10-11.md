# R12 长小票打印容器修复自测

## 范围与状态

- 用户反馈：含购买的订单小票到客户签字附近截断，纯权益正常。
- 线上只读核对订单 XS26101100163：预览包含客户、前台、店长三行签字，未漏生成内容。
- 本地修复打印前的根容器高度/宽度/溢出约束，打印 CSS 使用同一组约束；打印结束、取消、异常、未接管和关闭均恢复原 inline 值及 important 优先级。
- 保持单一小票实现，不区分购买/权益另建路径；字号、20mm 签字行、64mm 撕纸区、76mm 档的 63×210mm 页面均未改变。
- 开发与相称本地自测完成，待产品经理确认验收及同一打印机实体补测。未 commit、部署或 push。

## 诊断边界

线上根容器继承固定屏幕高度与 `overflow-y:hidden`。本地旧路径打印前 body 高 960px，小票 1157.76px；解除后 body 与小票均为 1238.22px，30 条明细时均为 2718.05px。这证实了打印前屏幕布局的裁剪风险。

Chrome PDF 引擎在修复前也能输出完整后续页，因此不能声称已在 Chrome PDF 中复现用户的物理截断，也不能据此证明 XP-76 驱动问题已解决。本次修复保护提前截屏的原生打印宿主，同时避免继承收银页根容器约束。实际设备是否还丢后续页、系统页码范围与走纸情况仍须现场验证。

本次只读检查 XP-76 PPD 默认 63.5×210mm，未改变系统打印机、队列、驱动、纸张设置；没有新增实体打印任务。

## 文件分类

- 前端源码：`前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js`。
- 自动化测试：`tests/cashier-v3/js/sales-order-receipt-print-contract.mjs`。
- 浏览器验证夹具：`tests/cashier-v3/js/receipt-pagination-fixture.mjs`，复用源码正本和实际收银全局 CSS，拦截 print，不向实体打印机发任务。
- 后端源码、数据库迁移、工程发布脚本：无。
- 生成物与证据：本目录及 `R12-long-receipt-2026-10-11/` 下修复前后 PDF、分页 PNG、本地浏览器截图。不含客户订单快照或认证资料。
- 注释更新：以上三个源码/测试文件均说明快照边界、预打印解除约束/恢复职责或验证夹具的无实体打印约束。

## 自测

1. `node tests/cashier-v3/js/sales-order-receipt-print-contract.mjs`：21 PASS，0 FAIL。覆盖原生打印前解除裁剪、afterprint、focus 返回、异常、未接管回退、关闭恢复原根样式与优先级，以及三纸宽、金额与权益区块、签字间距等原契约。
2. `node tests/cashier-v3/js/order-void-receipt-recovery.mjs`：2 组 PASS。
3. `node --check 前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js`：通过。
4. 本次文件 `git diff --check`：通过。
5. 浏览器实际执行夹具的打印准备，再由 CDP `Page.printToPDF`（`preferCSSPageSize:true`）导出：混合订单 2 页、30 条购买明细 4 页、纯权益服务 2 页。使用 pypdf 核对三行签字全部存在、30 条商品全部存在、所有页面为约 63×210mm，纯权益不含销售区块。
6. 通过 pdftoppm 渲染并逐页查看以上 8 页：明细、汇总、签字均完整，未见底部裁断。长名称沿用原先换行行为，不扩展本次范围。
7. 保存 `mixed-after-footer-browser.png`，三个签字标签均可见；完整屏幕布局在 `mixed-after-screen.png`。
8. 临时夹具服务和代理创建的标签页已清理；用户线上标签页保留，只关闭检查时打开的预览。

## 固定版本

基线 HEAD：`be821109147b046734c27fcf69472af44289e1b1`。

| 文件 | SHA-256 |
| --- | --- |
| 前端代码/cashier-v3/src/services/salesOrderReceiptPrint.js | a859800adf71a559f33500d0765263f8b9b1da2ff463632e82372f2365f113a8 |
| tests/cashier-v3/js/sales-order-receipt-print-contract.mjs | d1945eeab7f1bb5f0db0b6d94a60cfc764eef44884a8140e2a6c1c4db24e4ad6 |
| tests/cashier-v3/js/receipt-pagination-fixture.mjs | 04333f54f6144ca5a898e1a4644e1fe5eabaee1d4bb5e19b4310dc3e7e4bc6f3 |

已跟踪闭环文件补丁 SHA-256：`d098f936a01c1b9d40df9ae92ca869b9431a4e053db52d7cd864f42f5940b780`；新增夹具另以上表独立指纹标识。

现有其他任务暂存区指纹保持 `dc31b057aca12a17ee75ed7a86e023bfeba79933849b91605faa54e209c95b46`，未暂存、提交或修改其他任务文件。

## 产品经理补验

在本地最新源码入口测试原来含购买的混合订单，系统打印选全部页：确认客户/前台/店长三行签字及末尾走纸完整；再复测纯权益订单。尚无本补丁线上部署授权，用户给出的线上 URL 仍运行原版本。
