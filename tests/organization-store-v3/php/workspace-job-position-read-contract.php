<?php

declare(strict_types=1);

/** 组织人员列表必须回读新岗位事实，而不是只读旧 position 字段。 */
$root = dirname(__DIR__, 3);
$source = file_get_contents($root . '/后端代码/app/services/organization/OrganizationWorkspaceReadServices.php');
if ($source === false) {
    throw new RuntimeException('workspace read service missing');
}

$checks = [
    'reads authoritative staff job table' => strpos($source, "Db::name('staff_job_position')->alias('j')") !== false,
    'filters active current jobs' => strpos($source, "where('j.is_del', 0)") !== false
        && strpos($source, "where('j.status', 1)") !== false
        && strpos($source, "where('j.end_time', 0)") !== false,
    'returns job positions per assignment' => strpos($source, "'job_positions' => \$jobPositions") !== false,
    'returns job names for workspace labels' => strpos($source, "'job_names' => \$jobNames") !== false,
    'merges job names into employee roles' => strpos($source, 'foreach ($jobNames as $jobName)') !== false,
    'uses batch staff id query' => strpos($source, "whereIn('j.staff_id', \$scopeStaffIds)") !== false,
    'reads direct employee jobs' => strpos($source, "whereIn('j.employee_id', \$pageEmployeeIds)") !== false
        && strpos($source, "where('j.staff_id', 0)") !== false,
    'merges direct job names into employee roles' => strpos($source, '$directJobNames as $jobName') !== false,
];

$failed = array_keys(array_filter($checks, static fn ($ok) => !$ok));
if ($failed) {
    throw new RuntimeException('FAIL ' . implode('; ', $failed));
}

echo count($checks) . " PASS\n";
