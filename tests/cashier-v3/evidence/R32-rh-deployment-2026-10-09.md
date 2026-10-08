# R32 瑞昊退款闭环发布记录

- 产品经理于 2026-10-09 确认本轮测试无问题，并授权本轮 commit、部署瑞昊、push；另明确授权两项生产结构迁移。
- 功能提交 `f7de37592138f5569cc2a8926d8a05ab5198d6f4`，信息 `R32-00001 退款日期销售人及无原单退款闭环`，共 29 个文件。父提交 `83a3d2c4` 的 D3 薪资分类修复也随本次部署进入瑞昊；其他工作区未提交改动没有进入候选包。
- 唯一源码正本是 `美容源码/`。构建从功能提交归档提取门店端 `cashier-v3`、共享 `inventory-vue3` 与 `unified-query-vue3`；与本地 18091 热更新入口同源。分别安装两个锁定依赖包后 Vite 构建 1991 模块通过；首轮因共享库存依赖 `exceljs` 未安装失败，补齐临时发布目录依赖后重跑成功，未修改源码或锁文件。

## 文件与自测

- 前端源码 4 个：`PersonnelPerformanceOverlay.vue`、`cashierV3ActionManifest.js`、`cashierV3Bridge.js`、`OrderCenterView.vue`，均位于 `前端代码/cashier-v3/src/`。
- 后端源码 10 个：`CashierV3CommandGatewayServices.php`、`CashierV3ActionManifest.php`、`CashierV3C5MemberOrderModule.php`、`CashierV3OrderAttributionCorrectionServices.php`、`CashierV3OrderCenterRecordQueryServices.php`、`CashierV3OrderCenterUnifiedQueryContract.php`、`CashierV3OrderLifecycleModule.php`、`CashierV3RefundSalespersonServices.php`、`CashierV3UnifiedRefundServices.php`、`StoreUnifiedReportPhaseSixServices.php`，均位于 `后端代码/app/services/`。报表文件只提交已验收的退款扣减逻辑，并行中的未提交分类补读逻辑未夹带。
- 数据库迁移 8 个文件：`后端代码/database/upgrades/2026-10-08-退款销售人负向业绩/` 与 `后端代码/database/upgrades/2026-10-08-无原单目录商品退款/`，各含清单或说明、前检查、正式升级、后验证。
- 自动化测试 7 个文件：`tests/cashier-v3/js/` 的归属上下文、R26 网关、R32 日期合同；`tests/cashier-v3/php/` 的幂等历史上下文、R26 退款回滚、R32 销售人回滚与无原单目录退款回滚。
- 工程脚本：无。生成物：提交归档构建的 `dist/`，未提交。验收证据：本文件及两份 R32 本地固定版本记录，证据不作业务源码。
- 五项相关 JS 合同、两项 R32 PHP 事务回滚测试、PHP 7.4 对 10 份候选文件的语法检查、`git diff --cached --check` 均通过。业务边界注释已补充或更新于 `PersonnelPerformanceOverlay.vue`、`OrderCenterView.vue`、`CashierV3RefundSalespersonServices.php`、`CashierV3CommandGatewayServices.php`、`CashierV3UnifiedRefundServices.php`、`CashierV3OrderAttributionCorrectionServices.php`、`CashierV3OrderCenterRecordQueryServices.php` 和 `StoreUnifiedReportPhaseSixServices.php`。

## 生产备份与迁移

- 唯一目标：瑞昊 `rh.cc3798.com`、数据库 `ruihao`；其他客户实例未操作。生产 MySQL 5.7.44，PHP 7.4；迁移前新增字段、审计表、目录索引均不存在，退款调整 1 条，退款员工扣减事实 0 条。
- 独立备份目录 `/www/backups/rh.cc3798.com/20261009-r32-f7de3759/`：`ruihao-before.sql.gz`、`code-before.tar.gz`、`cashier-formal-before.tar.gz`、`cashier-preview-before.tar.gz`。四份压缩包通过 `sha256sum -c SHA256SUMS` 和数据库包 `gzip -t`。回滚点是本目录对应的旧后端、两套入口和数据库快照；发生新退款后不得直接恢复整库或删除新增列，应按退款事实审计前向修复。应用回滚须先核对没有后续发布与写入，再恢复旧代码及静态目录并重启瑞昊 Swoole。
- 顺序执行两份正式升级 SQL；升级日志键 `20261009-001-r32-refund-salesperson` 与 `20261009-002-r32-unlinked-catalog-refund`，均登记代码版本 `f7de3759` 及正式 SQL SHA-256。后验证确认 6 列、1 张改派审计表、目录索引 4 列、无无效版本、无孤儿扣减事实、历史卡项或单据退款目录字段仍为空。

## 部署、线上验证与推送

- 候选后端包 SHA-256 `13bf448055724168f3e0c3c74b727ec17760e037a1be2bf020bfa6ce354de77b`，前端包 `4045243664a828e1a2c76dacb9a0dc337ce799033909c515d777790cd9369541`；服务器端验签通过。仅覆盖上述 10 份后端 PHP；同一构建更新正式 `/view_cashier_v3/` 与瑞昊预览 `/preview-8080/view_cashier_v3/`，旧哈希资源保留。Swoole 重启后单 manager，20800 监听正常。
- 两套服务器端 `index.html` SHA-256 均为 `315662c0a716597f528feafaeed66710af6fecf32182ebd04ecef70489bf7982`。两套 HTTPS 入口与主要 JS、CSS、Excel 资源均可访问且资源指纹匹配本地构建；预览入口的 HTML 会被网关重写资源前缀，因此其外网响应指纹不同，资源内容一致。
- 匿名收银配置 API 返回 HTTP 200 和业务码 `410000`（请登录），符合权限边界。10 份线上 PHP 与候选文件逐字一致；生产退款仍为 1 条、合计 120000 分，扣减事实及改派审计均为 0。线上未创建真实退款或登录态业务操作，不能将本次只读冒烟验证冒充生产交易验收。错误日志最后修改时间早于本次切换。
- 功能提交已快进推送 Gitee `origin/release/store-cashier-c14-main`；远端 HEAD 复核为 `f7de37592138f5569cc2a8926d8a05ab5198d6f4`。推送未包含工作区其他已暂存或未提交的任务内容。
