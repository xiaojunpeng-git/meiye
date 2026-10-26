# R33 订单默认当天与新会话权益结账：瑞昊部署记录

## 固定版本与发布范围

- 产品经理已确认本轮固定版本，并明确授权 commit、部署瑞昊和 push。
- 业务提交：`44fc6bb5`（`R33 订单默认当天与新会话权益结账闭环`）。
- 目标仅为瑞昊 `rh.cc3798.com`；未连接、未部署其他客户实例。
- 本次发布门店端 `cashier-v3/dist` 与后端 `CashierV3CashierWorkspaceServices.php`；未执行 SQL、未修改配置或历史业务数据。

## 上线内容

- 订单中心销售、充值、退款、欠款、服务、补交、赠送、卡操作 8 个页签的首屏真实查询与日期控件统一默认为当天。
- 新会话或强制刷新后，浏览器结账快照没有服务端工作台草稿时，按非挂单结账继续；仍校验门店、操作人和工作台身份。
- 已存在工作台草稿时继续执行严格版本绑定，不放松并发与挂单清理约束。

## 部署前验证

- `order-center-unified-export-contract.php`：35/35 通过。
- `order-center-records-readonly.php`：36/36 通过。
- `hang-draft-checkout-decoupling-contract.php`：17/17 通过。
- 后端 PHP 语法与本轮四文件 `git diff --check`：通过。
- 门店端生产构建：Vite 处理 1972 个模块并成功产出；仅有既有 chunk 大小提示。
- 本地 18091 实测 8 个页签首屏请求均携带 `2026-09-24` 的起止日期。

## 备份与回滚点

- 独立备份目录：`/www/backups/rh.cc3798.com/20260924-r33-44fc6bb5-order-date-workspace-fix`。
- 后端备份 `backend-before.tar.gz`：SHA-256 `b2dac240ab765041c91c0f9b5b23615faac9a2fadc727dd43ac4402b853c3b7b`。
- 正式入口备份 `cashier-main-before.tar.gz`：SHA-256 `427b1b6d3dc9779aa75ab47fac8261b15a15bce6c5ffe855e9d4d56a35169f3e`。
- 8080 预览入口备份 `cashier-preview-before.tar.gz`：SHA-256 `427b1b6d3dc9779aa75ab47fac8261b15a15bce6c5ffe855e9d4d56a35169f3e`。
- 备份目录另保留两个入口切换前的完整运行目录；后端回滚后需重启瑞昊 Swoole。本次无数据库迁移。

## 文件一致性与运行状态

- 正式入口与 8080 预览入口 `index.html` SHA-256 均为 `50ee0293c02eb344fbf90b48dd74dde39c8c132a1bd58fb96e3cc722d044de71`。
- 两个入口均加载 `assets/index-ca7cca39.js` 和 `assets/index-703819fd.css`，资源 HTTP 200。
- `CashierV3CashierWorkspaceServices.php` 线上 SHA-256 为 `16238ffcc10676dd0bad6fba3e9fe508afc1e776e22a43b2cece87893a4718c5`，与提交文件一致。
- 后端 PHP 语法检查通过；瑞昊 Swoole 重启成功并保持 `manager_count=1`。

## 线上真实业务验证

- 瑞昊 8080 预览入口完成会员“李倩”的“补水面膜”权益结账，卡项为“6980随心挑”，数量 1 次，应付 ¥0。
- 页面明确显示“支付成功；权益使用成功，项目核销已完成”，未再出现“当前收银工作台已经变化”。
- 只读数据库对账：服务记录 `FW092400369`，结账请求 `CKR-1aad0cd09a868204da1604b7b979c9967cce2735`，服务状态 `completed`，项目数量 1。
- 同一结账请求仅生成 1 条有效权益核销事实，核销数量 1、状态 `effective`；完成回执状态 `completed`。
- 结账成功页面已保留在浏览器，供产品经理复核。

## 代码与实例边界

- 本轮新增与修改的日期默认、快照结账兼容和身份保护逻辑均保留职责与关键约束注释。
- 未部署平台端、会员端、数据库迁移、其他后端文件或其他客户实例。
