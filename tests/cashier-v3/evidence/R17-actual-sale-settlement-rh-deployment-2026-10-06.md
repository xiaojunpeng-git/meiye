# R17 瑞昊实际成交额权益结账修复发布记录

- 目标：仅瑞昊正式实例 `rh.cc3798.com`，站点 `/www/wwwroot/rh.cc3798.com/`；其他客户未操作。功能提交 `90efb471392b7573695f72a6b206cae17fe13fd8`，父版本 `331c046762431f8d6cf19b8d1cba345e30aa0d94`。产品经理已确认该固定版本验收并授权提交、部署瑞昊、push。
- 范围：仅替换两份后端 PHP：`app/services/cashier/v3/cashier/CashierV3EntitlementProjectionServices.php`、`app/services/cashier/v3/checkout/CashierV3DirectSnapshotEntitlementSettlementServices.php`。无前端构建、数据库迁移、配置或历史数据写入。未运行全量后端同步脚本，避免夹带工作区其他任务改动。
- 发布前：两份线上运行文件 SHA-256 分别为 `d04aee1c27bd8eec0c65d6f55c6c8bdffdf7740cf14f32b4f023a11861c68e1a`、`9e84234878f9d1ea494fb770c9cc778a940169dda5aaa369283a1771213e9f1f`，与功能提交父版本逐字节一致；瑞昊 PHP 为 7.4.33，Swoole 单 manager，20800 监听，磁盘余量约 208G。
- 独立备份与回滚点：`/www/backups/rh.cc3798.com/20261006-r17-90efb471/backend-before.tar.gz`，SHA-256 `823999e35ebaca0c1a13b5810e67792878129e0443f0cc4d638eacdc124e85d6`，已通过 `tar -tzf` 内容校验。仅在确认没有后续发布且运行文件仍为本轮指纹时，恢复包中的这两份旧 PHP 并重启瑞昊 Swoole；不回写业务数据库。
- 发布：从 `90efb471` Git 归档提取精准文件，暂存于同一独立备份目录 `stage/`；两份文件通过服务器 PHP 7.4 语法及 SHA-256 校验。再次核对旧指纹后原子替换，重启 `/etc/init.d/ruihao_swoole`；运行 SHA-256 分别为 `bb5b1f01a2e23b21e140166e3db5d32eb21e5a6f05bbed9a8552026474dabdc5`、`1c7cf9a5ac7dc8c7f9b4ad73bae83002235c173307d575cb4d5541cca9ca19cc`，与提交一致。
- 线上只读核验：Swoole `manager_count=1`，20800 正常监听；两份运行文件 PHP 语法通过；正式与预览门店端 HTTPS 入口均返回 200 且证书校验成功。未登录收银接口 `/cashierapi/v3/business-config/checkout-catalog` 返回 HTTP 200 及预期的“请登录”结构化响应；当日错误日志末尾 150 行未见 `Fatal error`、`Parse error`、`Uncaught`。未在生产创建真实订单或执行权益核销，生产业务交易结果不能由此次只读冒烟代替。
- 推送：线上验证后，仅将该提交快进推送至 Gitee `origin/release/store-cashier-c14-main`，远端复核指向完整 SHA `90efb471392b7573695f72a6b206cae17fe13fd8`；未推送 GitHub 镜像。此发布记录为部署后证据，不属于已验收功能提交。
