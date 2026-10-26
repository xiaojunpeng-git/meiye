# 第14轮：导购轮次与地推现金业绩本地自测

## 固定版本

- 基线 HEAD：`5f1eb677b2059ea98a642260def4003c6f7c7ae2`
- 本轮六个源码／测试文件的工作区 diff SHA-256：`d615745a83ca917f0e385fd53105eb0aa5de28413499a704f4a40ce374956afa`
- `前端代码/cashier-v3/src/services/orderCenterPersonnelDisplay.js`：`41c27f9dc1e084bd4a0c67a28b6af1231ed687091518078a7acfd0e71c70eef3`
- `前端代码/cashier-v3/src/views/OrderCenterView.vue`：`a3f7207c5f5a0ef08934e213330b64bbada7c822659ccaa1fbaaa3e168d2073a`
- `前端代码/cashier-v3/src/views/StoreBusinessReportView.vue`：`2e975b52666c74278a76446bbdba6d18457ef82b9d2a7f4aa3867caa10a4e8e2`
- `后端代码/app/services/report/StoreUnifiedReportPhaseTwoServices.php`：`3e0ff0ec2bf149790dbe8b9fb14ec5fa092c4d28e746f184f16e1203023bc5e4`
- `tests/cashier-v3/js/order-center-personnel-display-contract.mjs`：`e647737384e05c064af7b87593cbaaf43e9ff94a15376b409f49aa3b11112a02`
- `tests/cashier-v3/php/phase-two-report-projection-contract.php`：`e39bd0628718f4816c8cac17a0ca257992223204bfa59ab23e6c694fbda15fcf`

后端服务文件在本轮开始前已有无关的“新客汇总系统名称读取实例配置”工作区改动；以上整个文件指纹包含它，本轮不得将该段误纳入提交。

## 口径与验证

- 销售明细经 `sales_order_line.checkout_line_id` 关联结账导购轮次事实；不再以查询结果中的全局销售订单顺序推定轮次。
- 地推明细在第一次现金业绩前增加“无轮次现金业绩”；第 1～3 轮收款严格按导购事实归列，未选轮次收款不丢失。汇总表原有会员月度／首年有效收款口径不变。
- 订单中心导购姓名显示“第1轮／第2轮／第3轮／无”。
- `php -l`、PHP 投影合同、JS 展示合同、`git diff --check` 通过；门店端 `npm run build` 通过。
- 本地真实库样本：门店 163、会员 1136855、2026-10-09 的 1000 元进入 `cash_none`，第 1～3 轮为 0，合计行同为 1000；门店 105、会员 1136813、2026-10-05 的 1000 元只进入 `cash_1`，合计行一致。
- 同一查询的后端导出返回“无轮次现金业绩”位于“第一次现金业绩”之前、记录及合计均为 1000，并包含后端列名取值说明与口径版本 `field-acquisition-guide-round-v3`。
- RH 仅做只读溯源：订单 `XS26101200043` 的导购事实为第 1 轮；原第二次金额系旧逻辑把同店较早的游客订单算作第 1 笔。未修改 RH 数据或部署。

## 交付边界

- 前端源码：上述两个 Vue 页面和一个展示服务。
- 后端源码：上述统一报表服务。
- 自动化测试：上述 JS、PHP 合同。
- 数据库迁移、工程脚本、生成物：无本轮需提交内容；构建输出为忽略的生成物。
- 验收证据：本文件。待产品经理对固定版本确认验收；当前未提交、未部署、未推送。
