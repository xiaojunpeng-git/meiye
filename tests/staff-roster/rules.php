<?php
declare(strict_types=1);
/** 业务边界测试不连接数据库；独立验证未排班、整段服务、休息、跨店及边界相接。 */
require __DIR__ . '/../../后端代码/app/services/store/roster/StaffRosterRules.php';
use app\services\store\roster\StaffRosterRules as Rules;
date_default_timezone_set('Asia/Shanghai');
$checks = [];
function check(string $name, bool $ok): void { global $checks; if (!$ok) throw new RuntimeException($name); $checks[] = $name; }
$day = '2030-06-10'; $ts = fn(string $clock): int => strtotime($day . ' ' . $clock);
$work = ['store_id' => 1, 'schedule_type' => 1, 'schedule_date' => $day, 'start_time' => '10:00', 'end_time' => '18:00'];
$rest = ['store_id' => 1, 'schedule_type' => 0, 'schedule_date' => $day, 'start_time' => '12:00', 'end_time' => '13:00'];
check('启用后未排班不可预约', !Rules::allows([], 1, $ts('08:00'), $ts('22:00')));
check('完整服务在班次内', Rules::allows([$work], 1, $ts('10:00'), $ts('18:00')));
check('开始早于上班', !Rules::allows([$work], 1, $ts('09:59'), $ts('11:00')));
check('服务结束超下班', !Rules::allows([$work], 1, $ts('17:00'), $ts('18:01')));
check('全天休息', !Rules::allows([array_replace($rest, ['start_time' => '', 'end_time' => ''])], 1, $ts('10:00'), $ts('11:00')));
check('服务中途跨休息也拒绝', !Rules::allows([$work, $rest], 1, $ts('11:30'), $ts('13:30')));
check('休息开始前结束可约', Rules::allows([$work, $rest], 1, $ts('11:00'), $ts('12:00')));
check('休息结束后开始可约', Rules::allows([$work, $rest], 1, $ts('13:00'), $ts('14:00')));
check('休息跨门店生效', !Rules::allows([$rest], 2, $ts('12:00'), $ts('12:30')));
check('其他门店上班不能重叠', !Rules::allows([$work], 2, $ts('10:00'), $ts('11:00')));
check('旧班次午休仍有效', !Rules::allows([array_replace($work, ['breaks_snapshot' => '[{"start":"12:00","end":"13:00"}]'])], 1, $ts('12:00'), $ts('13:00')));
check('未排班全天不可选', Rules::disabled([], 1, $day) === [[$day.' 00:00:00','2030-06-11 00:00:00']]);
check('只有部分休息没有上班仍不可约', !Rules::allows([$rest], 1, $ts('14:00'), $ts('15:00')));
check('其他日期上班不算当天上班', !Rules::allows([array_replace($work,['schedule_date'=>'2030-06-09'])], 1, $ts('10:00'), $ts('11:00')));
$slots = Rules::disabled([$work, $rest], 1, $day);
check('选时与提交相同区间', $slots === [[$day.' 00:00:00',$day.' 10:00:00'],[$day.' 12:00:00',$day.' 13:00:00'],[$day.' 18:00:00','2030-06-11 00:00:00']]);
check('上一天休息不会污染次日', Rules::allows([$work,array_replace($rest, ['schedule_date' => '2030-06-09','start_time'=>'','end_time'=>''])], 1, $ts('10:00'), $ts('11:00')));
echo json_encode(['passed'=>count($checks),'checks'=>$checks], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),PHP_EOL;
