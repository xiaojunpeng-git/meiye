<?php
/**
 * Correct four erroneous 008 positions to the existing junior beautician position.
 *
 * This batch changes current job links in place because the source positions were
 * confirmed erroneous and have no linked business facts. It removes their catalog
 * rows and channel rules, then refreshes the employee permission projections.
 * No full database copy or per-person old-position history is created.
 */

declare(strict_types=1);

use app\services\organization\StaffJobPositionServices;
use think\facade\Db;

const C008_OLD_POSITION_IDS = [6, 13, 19, 20];
const C008_TARGET_POSITION_ID = 8;
const C008_ROLE_HASH = 'c84f95f1d7353aaa84e4445934d9d68dff477af6d946b4521b87d53e7038d950';
const C008_JOB_HASH = '7729a82b8b24f267066a2cf6e6d6901fd7793a28315edf9719f96d84002ff43d';
const C008_ORG_HASH = '06f24540800b79d826ef691cd06f6a14e216f43e3f8885a4bd1f4c896657dbce';
const C008_RULE_HASH = '81038d8ab3dade60be77c0f391f33bcc1f2a48a7d54c46277cb40ea444a76097';

/** Compare the independent customer database and port before any mutation. */
function c008AssertTarget(): void
{
    $identity = Db::query('SELECT DATABASE() db, @@port port')[0] ?? [];
    if (($identity['db'] ?? '') !== 'mohe_008' || (int)($identity['port'] ?? 0) !== 3307) {
        throw new RuntimeException('Refusing any database except mohe_008:3307');
    }
}

/** Hash string-normalized result rows so the reviewed source cannot drift. */
function c008HashRows(array $rows): string
{
    $normalized = [];
    foreach ($rows as $row) {
        $normalized[] = array_map(static function ($value) {
            return $value === null ? null : (string)$value;
        }, $row);
    }
    return hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/** Capture the exact five role definitions, 228 assignments and matching titles. */
function c008Source(bool $lock): array
{
    $suffix = $lock ? ' FOR UPDATE' : '';
    $roles = Db::query('SELECT id,name,status,use_platform,use_store,use_cashier,use_mobile,version '
        . 'FROM eb_position WHERE id IN (6,8,13,19,20) ORDER BY id' . $suffix);
    $jobs = Db::query('SELECT id,employee_id,staff_id,store_id,position_id,status,is_del,start_time,end_time '
        . 'FROM eb_staff_job_position WHERE position_id IN (6,13,19,20) ORDER BY id' . $suffix);
    $org = Db::query('SELECT oe.id,oe.employee_id,oe.job_title,oe.is_del FROM eb_organization_employee oe '
        . 'JOIN eb_staff_job_position j ON j.employee_id=oe.employee_id '
        . 'AND j.position_id IN (6,13,19,20) ORDER BY oe.id' . $suffix);
    $rules = Db::query('SELECT id,position_id,channel,status,MD5(rules) rules_md5 '
        . 'FROM eb_job_position_channel_rule WHERE position_id IN (6,13,19,20) ORDER BY id' . $suffix);
    foreach ([
        ['roles', $roles, 5, C008_ROLE_HASH],
        ['jobs', $jobs, 228, C008_JOB_HASH],
        ['org_titles', $org, 228, C008_ORG_HASH],
        ['channel_rules', $rules, 12, C008_RULE_HASH],
    ] as $guard) {
        if (count($guard[1]) !== $guard[2] || !hash_equals($guard[3], c008HashRows($guard[1]))) {
            throw new RuntimeException('Reviewed 008 ' . $guard[0] . ' source changed');
        }
    }
    $expectedNames = [6 => '2年美容师', 8 => '初级美容师', 13 => '头疗师', 19 => '美容师', 20 => '美甲师'];
    foreach ($roles as $row) {
        $id = (int)$row['id'];
        if (($expectedNames[$id] ?? null) !== $row['name']
            || (int)$row['status'] !== ($id === C008_TARGET_POSITION_ID ? 1 : 0)) {
            throw new RuntimeException('Position identity or active state changed');
        }
    }
    $employees = [];
    $staff = [];
    foreach ($jobs as $job) {
        $employeeId = (int)$job['employee_id'];
        $staffId = (int)$job['staff_id'];
        if ($employeeId <= 0 || $staffId <= 0 || isset($employees[$employeeId]) || isset($staff[$staffId])
            || (int)$job['status'] !== 1 || (int)$job['is_del'] !== 0 || (int)$job['end_time'] !== 0) {
            throw new RuntimeException('Old 008 job is not a unique current store assignment');
        }
        $employees[$employeeId] = true;
        $staff[$staffId] = true;
    }
    foreach ($org as $row) {
        if (!isset($employees[(int)$row['employee_id']]) || (int)$row['is_del'] !== 0) {
            throw new RuntimeException('Organization title does not match the reviewed employees');
        }
    }
    return [$jobs, $org];
}

/** Reject removal when an old position or job is linked to independent facts. */
function c008AssertNoOtherReferences(): void
{
    $positionTables = [
        'eb_job_position_publish', 'eb_position_yeji', 'eb_salary_info',
        'eb_staffing_quota', 'eb_staffing_quota_audit', 'eb_cashier_v3_service_customer_group_fact',
    ];
    foreach ($positionTables as $table) {
        $count = (int)(Db::query('SELECT COUNT(*) n FROM `' . $table . '` WHERE position_id IN (6,13,19,20)')[0]['n'] ?? -1);
        if ($count !== 0) {
            throw new RuntimeException('Old position has an external reference: ' . $table);
        }
    }
    $jobFactCount = (int)(Db::query('SELECT COUNT(*) n FROM eb_cashier_v3_service_customer_group_fact '
        . 'WHERE staff_job_position_id IN '
        . '(SELECT id FROM eb_staff_job_position WHERE position_id IN (6,13,19,20))')[0]['n'] ?? -1);
    if ($jobFactCount !== 0) {
        throw new RuntimeException('Old job has a historical business fact reference');
    }
    $duplicate = (int)(Db::query('SELECT COUNT(*) n FROM eb_staff_job_position oldj '
        . 'JOIN eb_staff_job_position newj ON newj.staff_id=oldj.staff_id '
        . 'AND newj.employee_id=oldj.employee_id AND newj.position_id=8 '
        . 'AND newj.status=1 AND newj.is_del=0 AND newj.end_time=0 '
        . 'WHERE oldj.position_id IN (6,13,19,20) '
        . 'AND oldj.status=1 AND oldj.is_del=0 AND oldj.end_time=0')[0]['n'] ?? -1);
    if ($duplicate !== 0) {
        throw new RuntimeException('An affected employee already holds the junior position');
    }
}

/** Verify the requested end state before committing any part of the batch. */
function c008Verify(array $jobIds, array $orgIds): void
{
    foreach ([
        'SELECT COUNT(*) n FROM eb_position WHERE id IN (6,13,19,20)',
        'SELECT COUNT(*) n FROM eb_staff_job_position WHERE position_id IN (6,13,19,20)',
        'SELECT COUNT(*) n FROM eb_job_position_channel_rule WHERE position_id IN (6,13,19,20)',
        "SELECT COUNT(*) n FROM eb_organization_employee WHERE job_title IN ('头疗师','美甲师','2年美容师','美容师')",
    ] as $sql) {
        if ((int)(Db::query($sql)[0]['n'] ?? -1) !== 0) {
            throw new RuntimeException('An erroneous 008 position still remains');
        }
    }
    if ($jobIds) {
        $jobs = Db::name('staff_job_position')->whereIn('id', $jobIds)->select()->toArray();
        if (count($jobs) !== 228) {
            throw new RuntimeException('An affected job link is missing');
        }
        foreach ($jobs as $job) {
            if ((int)$job['position_id'] !== C008_TARGET_POSITION_ID || (int)$job['status'] !== 1
                || (int)$job['is_del'] !== 0 || (int)$job['end_time'] !== 0) {
                throw new RuntimeException('An affected job was not corrected to junior beautician');
            }
        }
    }
    if ($orgIds) {
        $org = Db::name('organization_employee')->whereIn('id', $orgIds)->select()->toArray();
        if (count($org) !== 228) {
            throw new RuntimeException('An affected organization assignment is missing');
        }
        foreach ($org as $row) {
            if ($row['job_title'] !== '初级美容师' || (int)$row['is_del'] !== 0) {
                throw new RuntimeException('An affected organization title was not corrected');
            }
        }
    }
    $target = Db::name('position')->where('id', C008_TARGET_POSITION_ID)->find();
    if (!$target || $target['name'] !== '初级美容师' || (int)$target['status'] !== 1) {
        throw new RuntimeException('The target 008 position is unavailable');
    }
}

/** One database transaction prevents partially corrected people or roles. */
function c008Apply(): array
{
    $lock = Db::query("SELECT GET_LOCK('008_wrong_positions_to_junior_20261022',15) got")[0] ?? [];
    if ((int)($lock['got'] ?? 0) !== 1) {
        throw new RuntimeException('008 position correction lock unavailable');
    }
    try {
        Db::startTrans();
        try {
            [$jobs, $org] = c008Source(true);
            c008AssertNoOtherReferences();
            $jobIds = array_map('intval', array_column($jobs, 'id'));
            $orgIds = array_map('intval', array_column($org, 'id'));
            $now = time();
            if (Db::name('staff_job_position')->whereIn('id', $jobIds)->update([
                    'position_id' => C008_TARGET_POSITION_ID, 'update_time' => $now,
                ]) !== 228) {
                throw new RuntimeException('The 228 staff job links were not all corrected');
            }
            if (Db::name('organization_employee')->whereIn('id', $orgIds)->update([
                    'job_title' => '初级美容师', 'update_time' => $now,
                ]) !== 228) {
                throw new RuntimeException('The 228 organization titles were not all corrected');
            }
            /** Refresh the saved role and mobile projections before retiring the old catalog rows. */
            $service = app()->make(StaffJobPositionServices::class);
            foreach ($jobs as $job) {
                $service->projectStaffRoles((int)$job['staff_id']);
                $service->afterEmployeeAuthChanged((int)$job['employee_id']);
            }
            if (Db::name('job_position_channel_rule')->whereIn('position_id', C008_OLD_POSITION_IDS)->delete() !== 12
                || Db::name('position')->whereIn('id', C008_OLD_POSITION_IDS)->delete() !== 4) {
                throw new RuntimeException('The four erroneous position definitions were not removed');
            }
            c008Verify($jobIds, $orgIds);
            Db::commit();
            return ['corrected_jobs' => 228, 'corrected_titles' => 228,
                'removed_positions' => 4, 'removed_channel_rules' => 12];
        } catch (Throwable $error) {
            Db::rollback();
            throw $error;
        }
    } finally {
        Db::query("SELECT RELEASE_LOCK('008_wrong_positions_to_junior_20261022')");
    }
}

try {
    $command = (string)($argv[1] ?? '');
    if (!in_array($command, ['check', 'apply', 'verify'], true)) {
        throw new RuntimeException('Usage: php customer_008_position_unify.php check|apply|verify');
    }
    require getcwd() . '/vendor/autoload.php';
    $app = new \think\App(getcwd() . '/');
    $app->initialize();
    c008AssertTarget();
    if ($command === 'verify') {
        c008Verify([], []);
        echo json_encode(['ok' => true, 'mode' => 'verify', 'old_positions' => 0,
            'old_job_links' => 0, 'old_titles' => 0], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    if ($command === 'check') {
        c008Source(false);
        c008AssertNoOtherReferences();
        echo json_encode(['ok' => true, 'mode' => 'check', 'jobs' => 228,
            'organization_titles' => 228, 'old_positions' => 4,
            'old_channel_rules' => 12], JSON_UNESCAPED_UNICODE) . PHP_EOL;
        exit(0);
    }
    echo json_encode(['ok' => true, 'mode' => 'apply'] + c008Apply(), JSON_UNESCAPED_UNICODE) . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, '008 position correction stopped: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
