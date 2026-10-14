# R17 生美大单退款：瑞昊与渼约008发布记录

- 授权：产品经理确认本轮固定版本测试通过，明确要求 commit、部署瑞昊和渼约008、线上验证后 push。
- 本地闭环提交：`eb5a21a5`（五个文件）。本轮仅改报表后端、契约测试和验收证据；无前端源码、数据库迁移或生产业务数据改动。
- 发布候选：两站原报表服务均为 R16 基线 SHA-256 `1977c545857ebef0fbfa8fe608be527257efa1f88b100d1ad7a9b7b779adb09e`。本地提交与基线在同一报表方法中有交叉差异，因此从已提交的 R16 基线保留其他方法，只替换为 `eb5a21a5` 已验收的生美大单方法及其辅助方法。候选 SHA-256 `501f38afea12be85fbdf2fdd4444cf9b2f4786638d187df83e1a3a0fae46c124`；没有把脏工作区或旧版其他报表方法部署到线上。
- 依赖核验：两站均存在分类解析服务及卡项收款凭据、卡内分类分摊、退款归属修订、退款调整、支付与人员业绩事实表。瑞昊最新迁移 id `228`，渼约008 id `20`；本轮无需迁移。

## 瑞昊

- 目标 `/www/wwwroot/rh.cc3798.com/app/services/report/StoreUnifiedReportPhaseTwoServices.php`；独立回滚包 `/www/backups/rh.cc3798.com/R17-20261014-eb5a21a5.cGa1Nc/backend-before.tar.gz`，SHA-256 `3362f7e639d10a4657122604bcc75a372bab4c2a02f43b8fb0de9d90ced2a096`，`gzip -t` 通过。
- 候选经服务器 PHP 7.4 语法检查，替换前再次核对旧指纹；只替换该文件并重启 `ruihao_swoole`，manager_count=1。部署后文件指纹与候选一致。
- 生产只读报表查询返回 `beauty-large-order-refund-net-v4`、1 行；本地退款样本 TK2610140002 不在瑞昊生产，因此没有把本地四行样本冒称为线上事实。本地相同候选对该单查询出三条销售人负数及一条经理负数。
- 正式 `/view_cashier_v3/` 的 index/JS/CSS 磁盘指纹为 `aca255d6` / `44c5b862` / `89641df0`；预览 `/preview-8080/view_cashier_v3/` 为 `2ecf61ca` / `bc07d341` / `d8c1dd4b`。六个公网地址均 HTTP 200，正式三资源与预览 JS/CSS 的公网指纹与磁盘一致；预览 index 经入口路径重写，公网指纹 `fa7331d3`。两套前端原本不同，本轮均未更新或同步。

## 渼约008

- 目标 `/www/wwwroot/008.cc3798.com/app/services/report/StoreUnifiedReportPhaseTwoServices.php`；独立回滚包 `/www/backups/008.cc3798.com/R17-20261014-eb5a21a5.YkeZH9/backend-before.tar.gz`，SHA-256 `569d98b8272811de20eee2f1a13914a5e6d16091bea8be407962a45a8d47ac4f`，`gzip -t` 通过。
- 候选经服务器 PHP 7.4 语法检查，替换前再次核对旧指纹；只替换该文件并重启 `mohe-008-swoole.service`，状态 active。部署后文件指纹与候选一致。
- 生产只读报表查询返回 `beauty-large-order-refund-net-v4`、0 行；该日期无非空样本，非空退款人员与金额逻辑由已验收本地样本覆盖。正式门店入口及匿名配置接口均 HTTP 200。

## 回滚边界

任一站发现回归时先核对其运行文件未被后续版本覆盖，再仅恢复该站回滚包内的报表服务文件并重启该站独立 Swoole；不回滚数据库、不触碰其他客户实例或预览前端。
