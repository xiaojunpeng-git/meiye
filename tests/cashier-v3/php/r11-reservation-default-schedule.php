<?php

// 直接执行真实领域方法并注入时钟；不连接数据库、不创建预约事实。
$backend = getenv('BACKEND_ROOT') ?: dirname(__DIR__, 3) . '/后端代码';
require_once $backend . '/app/services/cashier/v3/CashierV3ResultCode.php';
require_once $backend . '/app/services/cashier/v3/CashierV3CommandException.php';
require_once $backend . '/app/services/cashier/v3/reservation/CashierV3ReservationModule.php';

use app\services\cashier\v3\reservation\CashierV3ReservationModule;
use app\services\cashier\v3\CashierV3CommandException;

$defaults = new ReflectionMethod(CashierV3ReservationModule::class, 'newReservationSchedule');
$schedule = new ReflectionMethod(CashierV3ReservationModule::class, 'schedule');
// 本地 PHP 8.5 与运行环境 PHP 7.4 共用测试；新版已默认允许反射调用。
if (PHP_VERSION_ID < 80100) {
    $defaults->setAccessible(true);
    $schedule->setAccessible(true);
}

function r11Equal($actual, $expected, string $label): void
{
    if ($actual !== $expected) throw new RuntimeException($label . ': ' . json_encode([$actual, $expected]));
}

// 主机时区不得影响上海的今天或下一个整点，整点打开也取下一小时。
date_default_timezone_set('America/Los_Angeles');
$fixtures = [];
foreach ([
    ['2026-10-11T00:00:00+08:00', '2026-10-11', '01:00', '02:00'],
    ['2026-10-11T14:00:00+08:00', '2026-10-11', '15:00', '16:00'],
    ['2026-10-11T14:20:00+08:00', '2026-10-11', '15:00', '16:00'],
    ['2026-10-11T14:59:59+08:00', '2026-10-11', '15:00', '16:00'],
    ['2026-10-11T21:59:59+08:00', '2026-10-11', '22:00', '23:00'],
    ['2026-10-11T22:00:00+08:00', '2026-10-11', '23:00', ''],
    ['2026-10-11T23:00:00+08:00', '2026-10-11', '', ''],
    ['2026-10-11T23:59:59+08:00', '2026-10-11', '', ''],
    ['2026-12-31T16:30:00Z', '2027-01-01', '01:00', '02:00'],
    ['2024-02-28T16:30:00Z', '2024-02-29', '01:00', '02:00'],
] as $case) {
    [$clock, $date, $start, $end] = $case;
    $draft = $defaults->invoke(null, new DateTimeImmutable($clock));
    r11Equal($draft['appointmentDate'], $date, 'today');
    r11Equal($draft['appointmentStartAt'], $start === '' ? '' : $date . 'T' . $start, 'next whole hour');
    r11Equal($draft['appointmentTime'], $draft['appointmentStartAt'], 'payload start aliases');
    r11Equal($draft['appointmentEndAt'], $end === '' ? '' : $date . 'T' . $end, 'same-day suggested end');
    r11Equal($draft['expectedEndAt'], $draft['appointmentEndAt'], 'payload end aliases');
    if ($end !== '') {
        $range = $schedule->invoke(null, $draft, 0);
        r11Equal($range[1] - $range[0], 3600, 'command consumes displayed hour');
    } else {
        try {
            $schedule->invoke(null, $draft, 0);
            throw new RuntimeException('incomplete/cross-day schedule must not be accepted');
        } catch (CashierV3CommandException $expected) {
            // 午夜空时间和 23:00 跨日结束都仍受原命令校验约束。
        }
    }
    $fixtures[] = $draft;
}

// 默认逻辑只挂在 create 分支；编辑、重算、保存不能二次调用时钟覆盖手填值。
$source = file_get_contents($backend . '/app/services/cashier/v3/reservation/CashierV3ReservationModule.php');
r11Equal(substr_count($source, 'self::newReservationSchedule()'), 1, 'one create-only call');
if (!preg_match('/\$draft = self::editorDraft\(\$header, \$options\);[\s\S]*?\} else \{[\s\S]*?\$draft = array_merge\(\$draft, self::newReservationSchedule\(\)\);/', $source)) {
    throw new RuntimeException('create/edit initialization boundary changed');
}
$manual = ['appointmentTime' => '2026-11-01T10:15', 'appointmentEndAt' => '2026-11-01T11:45'];
r11Equal($schedule->invoke(null, $manual, 60), [1793499300, 1793504700], 'manual schedule remains authoritative');

if (in_array('--fixtures', $argv, true)) echo json_encode($fixtures, JSON_UNESCAPED_UNICODE);
else echo "R11_RESERVATION_DEFAULT_SCHEDULE=PASS (10 clocks, Shanghai day, create-only, payload and same-day guard)\n";
