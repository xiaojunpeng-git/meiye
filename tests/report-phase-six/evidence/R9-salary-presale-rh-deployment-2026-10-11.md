# R9-00002 瑞昊发布记录

## 范围与版本

- 产品经理确认第 9 轮测试通过，并明确授权 commit、部署瑞昊和 push。
- 功能提交：`d8deba596d74c052c3b698207246690ef3449e54`（`R9-00001 售前业绩与结账快照闭环`），精确包含 5 个后端文件、6 个测试文件和 2 个固定版本证据文件。
- 仅部署 `rh.cc3798.com` 的 5 个后端 PHP 文件；未构建或替换前端，未执行迁移、历史订单修正或生产业务写入。订单 `XS26101100005` 保持原状。
- 发布前瑞昊迁移日志最新为 id `219`、`20261011-002-refund-attribution-adjustment`；本轮无新增迁移。

## 回滚与发布

- 服务器：`iZbp1fif4p5s7gj96nnec0Z`；站点：`/www/wwwroot/rh.cc3798.com`；PHP 7.4.33。
- 独立回滚目录：`/www/backups/rh.cc3798.com/20261011-r9-d8deba59/`，目录权限 0700。`code-before.tar.gz` SHA-256 为 `05bac8bb28b866376e25f462dafe9732169a5cffd0d4596b90b90ec1e42fa683`。五个原文件逐项通过备份内 `SHA256SUMS-before` 校验。
- 从功能提交生成精确五文件候选包，SHA-256 为 `05debe275d71c07d84eed8ffcf6197c8d3321ac958f30a5301ed8d4d14b486c4`；生产 PHP 7.4 对五文件的语法检查全部通过。
- 使用瑞昊实例代码锁，切换前重查五个旧运行文件、备份和候选指纹；原子替换后只重启 `/etc/init.d/ruihao_swoole`。失败自动恢复旧文件的保护未触发。
- 回滚时先确认五个运行文件仍为本轮新指纹，再从上述回滚包逐文件原子恢复并重启瑞昊 Swoole；不得覆盖后续发布或以代码回滚代替业务数据冲销。

| 文件（相对站点根目录） | 发布前 SHA-256 | 发布后 SHA-256 |
| --- | --- | --- |
| `app/services/report/StoreUnifiedReportPhaseSixServices.php` | `17ef6302b472942310f7c6505ee3d8b726588bf54b1db0b30f5eea966df8482c` | `4b13a18daa086d625036370e848e7cffe624d11824d0c4144f985245c5701bbe` |
| `app/services/cashier/v3/cashier/CashierV3CashierWorkspaceServices.php` | `11d935abf0f4462f2de3502f64c923810f079f862f1fdd1841e336b2f79a28c4` | `a94af4480880d98ffe155456dc044010ec86661fba93a90e78a67a1b4631bdf4` |
| `app/services/cashier/v3/settlement/CashierV3CheckoutSettlementKernel.php` | `d46e6b434fa1227dc6ca2d7f4c550f63b85a6e5bdb2af340f30e246b4746342d` | `3d676ca1cc01c75a92a7fe3c82edaf0a61e127bfb0a0ea42daf65ca964111eae` |
| `app/services/cashier/v3/settlement/CashierV3CheckoutProjectionServices.php` | `933c4f5ec15a4e6a6fbfd0a2bafcf7f4bfe31d3ec3481c18c6d2271217cb3e33` | `ee7bc99c8b970e60d0b14f9e72f1e166300da4fcfe5df657e22968f3eb5215df` |
| `app/services/cashier/v3/order/settlement/CashierV3SalesOrderPlanV1.php` | `6c4f2d0b33bd7d5f8aa9a9ec59eae714fe7a84ccfcfba2286720f2df97942f5d` | `e72c279fcc4724c8c312ea0e6a82c2140a8a482b35c9aa66b998b63221d5f011` |

## 线上只读核验

- 重启后 `manager_count=1`，PID `7966`，20800 监听正常；五个运行文件均与功能提交指纹一致，且再次通过 `php -l`。
- 瑞昊正式及预览门店入口均 HTTP 200；匿名 `/cashierapi/config` 返回业务码 410000“请登录”，鉴权仍生效。
- 在生产框架中只读查询 2026-10-08、门店 126 的员工薪资汇总：7 行，存在独立的“售前业绩”列；按员工和门店下钻的售前明细分类核对通过，抽样员工该列 0 行。不以此冒充生产真实结账写入验收。
- 部署前错误日志末尾存在库存不足和订单更正拒绝记录；本轮未修改该模块，也未将其归因为本次发布。
- 本地固定版本的报表只读回归、结账快照回归、结账合同、报表合同和订单中心前端合同均已通过，详见第 9 轮固定版本证据。

## 文件分组

- 前端源码：无；后端源码：上述 5 个 PHP 文件；数据库迁移：无。
- 自动化测试：功能提交中的 6 个测试文件；工程脚本与生成物：无。
- 验收证据：功能提交中的 2 个固定版本文件；发布证据：本文件。
- 工作区其他任务已有的暂存、修改和未跟踪文件未进入本轮功能提交或部署。
