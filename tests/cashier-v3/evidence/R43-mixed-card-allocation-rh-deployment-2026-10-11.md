# R43-00004 混合结账卡项分摊瑞昊发布记录

- 产品经理确认已测试本次修复，并明确要求提交、部署瑞昊、push。目标仅为 `rh.cc3798.com` / `ruihao`；历史缺失分摊的补录不在本次授权范围。
- 唯一源码正本中的闭环提交：`c5ac635c957467e63b7d9083766de9192d8f1de8`，只含 3 个后端源码、2 个自动化测试和 1 个固定版本自测记录。Gitee 瑞昊发布线原基线 `4c551130379ce8b5824d9e1ca9fd82dff54a3daf` 与本地开发线历史分叉，已用同一 6 文件的精确发布集成提交 `e4d5107efec9bec665f6bcd732718cd67520c52f` 快进到 `release/store-cashier-c14-main`；未推送本地开发线的其他提交、暂存或未提交改动。

## 自测与文件边界

- 5 个代码／测试文件相对本地开发基线的补丁 SHA-256：`d07e5e21da775b0826887c41ed0dee9d69486647ea5a5f28b46a66307d44daa3`。本次 PHP 7.4 三个后端文件语法检查、混合效果契约 5/5、编排器契约 10/10、卡项分类分摊契约和 `git diff --check` 均通过。
- 后端源码及线上目标文件：`app/services/cashier/v3/settlement/` 下的 `CashierV3CheckoutSubmissionExecutionPort.php`、`CashierV3CheckoutSubmissionOrchestrator.php`、`ThinkPhpCashierV3CheckoutSubmissionExecutionPort.php`。线上新文件 SHA-256 依次为 `a5e3ff93a41ece46efd962fad09229f08cee1d6a57006ee2728bdf6bec114514`、`bf82c262e6bfe738de149b80b4be30132492890b571211ea8fb13668dc8e66b6`、`67d51117740dd5f56ae3fd1ef36c6c704f6a0e72be1bb7ee40f6f6b7676469c4`，与集成提交完全一致。
- 自动化测试：`tests/cashier-v3/php/c2-checkout-submission-orchestrator-contract.php`、`tests/cashier-v3/php/checkout-mixed-sale-effects-contract.php`。注释更新在执行端口接口和生产实现中，说明发卡快照、卡项实际分类及同事务写入边界。
- 前端源码、数据库迁移、工程脚本：无。生成物仅为远端临时候选文件及备份，不作为源码提交；本文件及前述自测记录为验收证据。
- 隔离 MySQL 5.6 结账测试库的迁移结构仍落后，未取得本路径完整数据库集成测试通过的证据；契约测试不冒充该项。产品经理已对本项实际测试并授权发布。

## 备份、部署与线上验证

- 部署前瑞昊站点、`ruihao` MySQL 5.7.44、Swoole 20800 及三份旧文件哈希均已核对。独立备份目录：`/www/backups/rh.cc3798.com/20261011-222349-r43-card-allocation-c5ac635c/`。旧 PHP 包 SHA-256 为 `e1d12321794bf5dd76c2c11dc38134ea3e72b4a995dc6e5bb6a146bbda054b3c`；发布前整库快照 SHA-256 为 `0693ad41e1c8d6893ab14803afe43c13675db801ae1f683d33523c021e58d753`。两包均通过 `gzip -t`；数据库包约 241 MB。
- 仅替换上述三份 PHP 文件并重启 `/etc/init.d/ruihao_swoole`。线上 PHP 7.4 三文件语法检查再次通过，Swoole 为单 manager，20800 正常监听；从服务器和本机访问 `https://rh.cc3798.com/cashierapi/config` 均为 HTTP 200，重启后五分钟内最近日志未检出 `PHP Fatal`、`Uncaught` 或 `Parse error`。线上未执行真实资金结账，因此这里是部署与只读健康检查，不把它写作线上完整业务交易验证。
- 本次没有执行 SQL 迁移、生产数据库写入、其他实例部署或前端打包。前向代码只影响新发生的混合结账；历史 19 条缺失的分类及项目分摊仍需另行授权、逐单核对与幂等补录。线上发生新业务后不能直接用整库快照覆盖回滚；如需撤回代码，先核对后续订单状态，再从本次旧 PHP 包恢复并重启 Swoole。
- 瑞昊发布线已推送至 Gitee `e4d5107efec9bec665f6bcd732718cd67520c52f`。本记录随后单独归档，不改变已部署业务代码。
