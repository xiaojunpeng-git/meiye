# 008 积分年度到期安全修复隔离自测

范围：仅本地通用源码与独立 MySQL 5.7 `integral_expiry_test` 数据库；没有连接或修改 008 正式库。测试完成后已删除隔离库、隔离账号及容器内临时测试脚本。

## 执行

1. `php -l` 检查 `UserIntegralServices.php`、`UserIntegralJob.php` 和集成测试脚本，均无语法错误；`git diff --check` 无空白错误。
2. 在隔离库以真实 ThinkPHP 服务运行 `tests/customer-008/php/integral-expiry-isolated-integration.php`：输出 `PASS 历史时间/预览一致/2025窗口/余额上限/重复幂等/零余额迟到任务/旧清零复用/失败回滚及重试/跨年清零隔离`。零余额会员在首次任务写入零扣减守卫；后来新增 30 分再重放旧任务，积分仍为 30 分。
3. 在两个独立 PHP 进程中并行清算同一会员 UID 6，两个进程均返回成功；读回输出 `PASS 并发双进程只扣一次、只写一笔清零流水`。
4. `02-正式升级.sql` 与 `03-升级后验证.sql` 在隔离 MySQL 5.7 执行成功；验证结果：守卫表存在、`uniq_uid_period_start` 为唯一索引、重复守卫组 0、升级键登记 1 条。
5. 008 正式站点只读运行启用脚本 `check`，确认为 `mohe_008:3307` 与 Redis DB 0，当前返回 `blocked`，仅因 `expiry_guard_missing`、`expiry_guard_upgrade_not_recorded`、`member_batch_missing`；两条年度配置值为空、年度定时器不存在。脚本对运行时 `Config::get('cache.stores.redis.select/prefix')` 的检查已通过，确认 `0` 与 `008:`。未对正式站点写入。

故障注入：在写 `system_clear` 流水时由数据库触发器抛错，会员积分回滚到原值、守卫 0 行；去掉故障后用原固定窗口重试，余额、流水和守卫各自只完成一次。

## 限制与上线顺序

本次隔离测试不代表产品经理验收或 008 线上验证。正式启用必须先把通用迁移和代码部署到 008，再导入合并后的会员积分及完整原时间流水、逐人试算和对账，最后单独启用 008 年度配置及定时器并刷新其隔离 Redis DB 0。其他实例不随本任务更新。任务通过 `Queue::getValues()` 明确携带默认 `errorCount=3`，第三次失败将被删除；需监控失败日志、队列积压和年度应清算 UID 与守卫/流水差集，并按原固定窗口补偿缺口。`BaseJobs::fire()` 的缺字段默认 0 仅对其他未使用该封装的队列任务适用。

缓存调用链已核对：`SystemConfigService::clear()` 清除 008 Redis 配置标签并返回布尔值；`SystemTimerServices::setAllTimerCache()` 重建 008 DB 0 的 `mc:system_timer:map/page`。启用脚本在提交后检查配置实际读取值及缓存中的定时器 ID、开关、类型和周期；目前未执行正式 `apply`，所以这项后验只完成代码路径核查，须在实际启用时执行 `verify` 并记录结果。
