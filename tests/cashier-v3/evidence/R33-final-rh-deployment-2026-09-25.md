# R33 第33轮收尾：瑞昊部署记录

## 固定版本与范围

- 产品经理已完成第33轮验收，并明确授权 commit、部署瑞昊和 push。
- 部署固定源码 HEAD：`a2ca5d3a`（`R33 已用卡项作废提示闭环`）。
- 本次收尾包含已验收提交：`dbc69d4d`、`38616501`、`119a3ac7`、`a2ca5d3a`。
- 目标仅为瑞昊 `rh.cc3798.com`；未连接、未部署其他客户实例。
- 本次无 SQL、无数据库迁移、无配置修改、无历史业务数据修改。

## 上线内容

- 会员也可将导购轮次选为“无”；非会员继续默认“无”，不伪造第0轮业绩事实。
- 业绩分配弹窗将导购和销售经理改为左右分栏，各自标题和说明分开，查询按钮与标题同行靠右，内容区基本铺满弹窗。
- 已使用卡项作废时明确显示：“卡项「名称」已使用过，不能直接作废原销售订单，使用过的卡项只能停用。”
- 卡项已用、剩余和总次数仍保留在结构化诊断明细中，未放宽原有作废账权保护。

## 本地验证与构建

- `personnel-performance-overlay-contract.mjs`：通过。
- `guide-round-fact-contract.php`：全部通过。
- `used-card-void-message-contract.php`：通过。
- 本轮后端 PHP 语法、`git diff --check`：通过。
- 门店端生产构建成功，Vite 处理 1972 个模块；仅有既有 chunk 大小提示。
- 订单生命周期总合同中 `service_void_frontend_sends_no_version_context` 仍为本轮修改前已存在的独立断言失败；本轮不修改该服务作废语义，未将其记录为通过。

## 备份与回滚点

- 独立备份目录：`/www/backups/rh.cc3798.com/20260925-r33-a2ca5d3a-final`。
- `backend-before.tar.gz`：SHA-256 `b4976eea07da905b1143e8e59a41ef8437b93e7ad1ac3b9e96bb8dad96ece0ef`。
- `cashier-main-before.tar.gz`：SHA-256 `6f344043e770c0433207c0638311055669f3bdf00095bede9b85c639e50ad4e1`。
- `cashier-preview-before.tar.gz`：SHA-256 `6f344043e770c0433207c0638311055669f3bdf00095bede9b85c639e50ad4e1`。
- 回滚时恢复上述对应文件与两个门店端目录，清理运行缓存并重启瑞昊 Swoole；本次不涉及数据库回滚。

## 部署结果与线上校验

- 正式入口与 8080 预览入口 `index.html` SHA-256 均为 `807fc0320b9594d25ffb9846a3d3b31dedbff31e49d590c2a50f382eded870c6`。
- 两个入口均加载 `assets/index-375974e3.js` 和 `assets/index-1b992ab6.css`，页面及资源 HTTP 均为 200。
- JavaScript 资源 SHA-256 均为 `485a81b4e576f7252e5e01951d91e92f11d4f34c07fe4752add36404d93bb771`。
- 6 个后端发布文件的远端指纹均与本地固定版本一致，远端 PHP 语法全部通过。
- 已在服务器发布文件中核对“使用过的卡项只能停用”文案。
- 瑞昊 Swoole 重启成功，`manager_count=1`；发布后运行日志无新增 `Fatal error`、`Parse error` 或 `Uncaught`。
- 浏览器已切换到 `/preview-8080/view_cashier_v3/?release=a2ca5d3a#/order-center`，登录会话正常，订单查询日期和首屏数据默认为 `2026-09-25`。

## 实例边界

- 本次只发布已提交的门店端构建产物和 6 个收银 V3 后端文件；工作区其他未提交修改未进入瑞昊。
- 未部署平台端、会员端、数据库迁移、其他后端文件或其他客户实例。
