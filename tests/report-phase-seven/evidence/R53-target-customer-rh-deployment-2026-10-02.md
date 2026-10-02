# R53 目标页服务客数统一口径：瑞昊发布记录

- 授权：产品经理对固定版本明确回复“我测试没有问题,commit,部署瑞昊,push”。目标仅瑞昊 `rh.cc3798.com`，独立数据库 `ruihao`；其他客户实例未连接、未变更。
- 本地业务提交：`7e2c56a6be750fe798b7c90e39846968c506d587`，精准包含 4 个本轮源码/测试/自测文件。线上上一固定隔离发布版本为 `b482b1be09ac3d67b0c0daf2c455b9681f787648`；只叠加上述 4 文件的本轮隔离发布提交为 `9c93a6795bc25bab5f4c6f7f42eeb7473dcadefa`，分支 `release/r53-target-service-customer-20261002`。目标文件在原业务提交父版本与上一线上发布基线完全相同，未带入混合开发分支其他提交或脏工作区。
- 唯一开发源码正本仍是本机 `美容源码/`；服务器文件只作为部署结果。无前端源码改动，未重新打包或覆盖正式/预览静态入口；只更新瑞昊一项 PHP 只读模型。无数据库迁移、配置修改、历史数据回填、真实资金或服务写入。

## 发布前核对与备份

- 服务器 `iZbp1fif4p5s7gj96nnec0Z`；正式站 `/www/wwwroot/rh.cc3798.com/`，数据库只读确认 `ruihao`，升级日志发布前 `202` 条；PHP CLI 7.4.33，磁盘剩余约 215G，瑞昊 Swoole 单 manager。
- 现网目标文件与上一发布基线均为 SHA-256 `03013d2f6e693eb25c35b1b10311176d7a810bdd523e44776fe582f7768ad042`。已部署统一客数读层与资格规则的 SHA-256 分别为 `772a6a4e469328c3b04209d84c74e9a7f0ee10633eb9636d6f2451caeb7b6495`、`f8789cc0e4d97c1022af3dce799b2e452a3b56c1a083fb8c68ef0fe7f4ddd076`，与隔离发布基线一致。
- 瑞昊独立备份目录 `/www/backups/rh.cc3798.com/20261002-r53-target-metric-9c93a679/`；`backend-before.tar.gz` 仅包含被替换的后端文件，SHA-256 `52946af9ac0cc0dc6a8be3efd31565f5bd64e1cf7133264d63e6ca50d8b1a39c`；`gzip -t` 和 tar 文件清单检查通过。无数据库写入，本轮无需数据库回滚，沿用瑞昊 ECS 每日快照。
- 从隔离 Git commit 固定归档提取的待发布 PHP SHA-256 为 `ef5ee3c744b8fa41a1a570641c743a8df58e915d5de8f81fa5a0919c70e711d8`；远端暂存指纹一致，PHP 7.4 lint 通过，启用前再次守卫旧文件指纹。

## 发布与线上验证

- 原子替换瑞昊 `app/services/cashier/v3/dashboard/CashierV3StoreTargetDashboardReadModel.php`，仅重启 `/etc/init.d/ruihao_swoole`；新 manager PID `9434`。发布后目标文件 SHA-256 为 `ef5ee3c744b8fa41a1a570641c743a8df58e915d5de8f81fa5a0919c70e711d8`。
- 正式 `https://rh.cc3798.com/view_cashier_v3/?release=9c93a679` 与预览 `https://rh.cc3798.com/preview-8080/view_cashier_v3/?release=9c93a679` 均返回 HTTP 200；本次未修改前端资源，HTTP 页面健康检查不冒充已登录浏览器业务验收。
- 线上 `ruihao` 只读调用新目标页读模型：完成服务 `FW100200905` 为“朋友不算”，关联员工 661；目标页服务人次 `6`、服务人数 `6` 与统一读层 `60/10`、`60/10` 一致，消耗业绩 `80600` 分仍保留，指标版本为 `cashier-v3-facts-v2`。线上同号 `FW100200008` 实际为“本人”，与本地用户截图数据不同；因此线上没有把本地样本误当成线上同一业务事实，也没有修改任何线上服务记录。
- `.env` SHA-256 `24b6eef35143aed11786d9bdac4f53eb160fc610974c3fd4cb0b98463191ee82`、`config/swoole.php` SHA-256 `6578c0bde1be74fc99741289e83ff61976029bf8e1e416908253b931a91027de` 发布前后未变；Swoole manager 数 1，当日错误日志最近 150 行未见 Fatal/Parse/Uncaught，数据库升级日志发布后仍 202 条。
- 回滚边界：仅当目标文件仍为本次新 SHA 且没有后续发布时，从本次瑞昊备份恢复该单文件并重启瑞昊 Swoole；不得覆盖 `.env`、其他源码、静态包或其他任务成果。
- push 目标：仅 Gitee `origin` 的 `release/r53-target-service-customer-20261002` 分支。线上只读核验通过后执行并以远端 SHA 核对；不推送混合开发分支、GitHub 或其他客户版本。
