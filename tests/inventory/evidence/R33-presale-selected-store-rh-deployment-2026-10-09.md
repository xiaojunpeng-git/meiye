# R33 瑞昊会员领用选店范围发布记录

- 授权与验收：产品经理确认本轮测试无问题，明确要求 commit、部署瑞昊、push。功能提交 `37479217`；仅部署瑞昊 `rh.cc3798.com`，未操作 `007/008/012`。
- 发布范围：只替换正式站 `app/controller/store/product/inventory/PresaleClaim.php` 与 `app/services/cashier/v3/presale/CashierV3PresaleClaimServices.php` 两份 PHP。无前端构建、数据库迁移、配置、Redis、队列或计划任务变更。数据库独立确认为 `ruihao`；发布前升级日志最近两项为 `20261009-002-r32-unlinked-catalog-refund` 与 `20261009-001-r32-refund-salesperson`。
- 前置核对：两份线上原文件 SHA-256 分别为 `5ff398b7176bf9842fa69923007da183d9c72263dd34f576c7ab8ee6056bb087`、`4f4ca232a0f253ca1cdb71a40dd06a9e7ea20efc9d75c292b63cd2a33860a729`，与功能提交父版本一致；PHP 7.4.33、磁盘余量约 203 GB、瑞昊 Swoole 单 manager 正常。
- 独立备份与回滚点：`/www/backups/rh.cc3798.com/20261009-r33-presale-37479217/backend-before.tar.gz`，SHA-256 `0efffdf359eba8fba808c60ebf4a77a3c9065550b198276f37a15dfdf6924674`；归档两条目标路径可列出且通过 `gzip -t`。如需回滚，先核对无后续发布，再仅恢复这两份 PHP 并重启瑞昊 Swoole，不覆盖生产数据库。
- 发布与校验：新文件先放独立暂存目录并由瑞昊 PHP 7.4 逐个 `php -l` 通过，随后精确替换。线上文件 SHA-256 分别为 `70a42976d5011094d484f3d1de7593173463c63d13b7c88b7a67c759180a8986`、`8386229c33ba7215e3b73bc1036457d68fccfdd3cf1f287d8cbae13c9a64e7dc`，与验收版本一致。`ruihao_swoole` 重启后 manager_count=1、20800 正常监听。
- 线上只读验证：使用生产真实有效选店会话对应身份做服务层查询，当前门店（126）可领用预售返回 236 条、首批 20 条；同一会话跨店（125）请求被拒绝。正式和预览门店入口均返回 HTTPS 200，最近 120 行 Swoole 日志未见 Fatal/Parse/Uncaught。未在生产环境提交领用、作废或库存写入；该冒烟不等于完整交互验收。
- 结果：瑞昊正式后端发布成功；其他客户实例保持原状。
