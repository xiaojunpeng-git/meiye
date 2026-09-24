# R32 纯权益结账同步预约：瑞昊部署记录

## 发布范围

- 发布时间：2026-09-24 14:50 CST
- 目标实例：瑞昊（`rh.cc3798.com`）
- 业务提交：`acfe8f60 R32 纯权益结账同步预约闭环`
- 数据库迁移：无；本次不执行远程写库。
- 其他客户实例：未部署、未改动。

本次只发布业务提交内的四个预约后端服务文件，以及由同一固定版本门店端源码生成的 `cashier-v3/dist`。工作区内其他未提交改动未被暂存或部署。

## 部署前备份与回滚点

- 备份目录：`/www/backups/rh.cc3798.com/20260924-r32-acfe8f60-entitlement-reservation-qszFsD`
- `backend-before.tar.gz`：`4fe8bf91d21ea9c0811a958cb09d38721c168f9050127d5f73438767199451c4`
- `cashier-main-before.tar.gz`：`066ccf6faaa028307473c8cbc72d6736b5a8a9aa4777bae9ce7374c21e9dcb91`
- `cashier-preview-before.tar.gz`：`066ccf6faaa028307473c8cbc72d6736b5a8a9aa4777bae9ce7374c21e9dcb91`

若需回滚，恢复上述后端与两个门店端静态包，然后重启瑞昊 Swoole 与相关 AI 服务。由于本次没有数据库结构或数据迁移，不需要数据库回滚。

## 后端文件一致性

远端 PHP 语法检查全部通过，部署后 SHA-256 与本地固定版本一致：

- `CashierV3CheckoutReservationClosureServices.php`：`483b894aac2d8f4edf6f57c2ff23873c4ba4b6cfe151403a7403cb1452a63391`
- `CashierV3ReservationModule.php`：`0b3e1557d842a66f30de69d2cc0f355e6f8ce1ecd0b531c0bba0fd4fff9d8c33`
- `CashierV3ReservationDetailQueryServices.php`：`946b5227802cc9940d164051014db96149d37cab602442088abed0d08786999f`
- `CashierV3ReservationPartitionProvider.php`：`ff3e2451ff8808778cbc92117dd7c5caf074b042dcc45f16f7589ffd2b22082c`

## 门店端构建一致性

- 本地 `cashier-v3/dist` 文件集哈希：`287567d3ed03fb340ac9ea04ce8c37af34f32799e67e18363c7b41ca20b351ef`
- 瑞昊正式入口文件集哈希：`287567d3ed03fb340ac9ea04ce8c37af34f32799e67e18363c7b41ca20b351ef`
- 瑞昊预览入口文件集哈希：`287567d3ed03fb340ac9ea04ce8c37af34f32799e67e18363c7b41ca20b351ef`
- 主资源：`assets/index-6052a8bf.js`、`assets/index-8433ac9b.css`

## 线上验证

- 正式入口 `https://rh.cc3798.com/view_cashier_v3/?release=acfe8f60`：HTTP 200。
- 预览入口 `https://rh.cc3798.com/preview-8080/view_cashier_v3/?release=acfe8f60`：HTTP 200。
- 两个入口的 `assets/index-6052a8bf.js`：HTTP 200，大小均为 1,884,907 字节。
- 未登录调用收银工作台动作接口：HTTP 200，业务状态 `410000`、提示“请登录”，鉴权边界正常，无 5xx。
- 瑞昊 Swoole：`manager_count=1`；`mohe-ai-supervisor.service`：`active`。
- 发布后近期日志未发现 `Fatal error`、`Parse error` 或 `Uncaught`。

本次线上验证不执行真实生产结账，不制造新的生产业务记录；真实业务路径已由产品经理在固定版本上验收通过。
