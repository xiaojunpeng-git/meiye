<?php
declare(strict_types=1);
namespace app\services\store\roster;
use think\facade\Db;
use app\services\cashier\v3\CashierV3TransactionGuard;

/** 复用已有排班配置键，三端直接读取数据库；关闭后不把新排班套入原预约规则。 */
final class StaffRosterSwitch
{
    public const KEY = 'reservation_schedule_manage';
    public static function row(bool $exclusive = false): array
    {
        $query = Db::name('system_config')->where('menu_name', self::KEY)->where('is_store', 0);
        // 预约/排班共持共享锁，配置保存持独占锁：切换与业务提交有明确的先后顺序。
        if (CashierV3TransactionGuard::isInTransaction()) $query->lock($exclusive ? true : 'LOCK IN SHARE MODE');
        return (array)($query->find() ?: []);
    }
    public static function enabled(): bool
    {
        $row = self::row();
        $raw = (string)($row['value'] ?? '0'); $value = json_decode($raw, true);
        return (int)($value === null ? $raw : $value) === 1;
    }
}
