<?php
namespace app\services\employee;

use app\services\BaseServices;
use think\facade\Db;

/**
 * O2：统一员工主档归并 / dry-run / migrate / reconcile
 * - 仅按严格标准手机号归并
 * - 不改登录、source mode、菜单、负责人自动生成
 */
class EmployeeMigrateServices extends BaseServices
{
    public const PHONE_REGEX = '/^1[3-9][0-9]{9}$/';
    public const DEFAULT_AVATAR = '/static/images/staff/avatar_male.png';
    public const DEFAULT_AVATAR_TYPE = 1;

    /**
     * @return array{report:array,planned:array,source_hashes:array}
     */
    public function dryRun(): array
    {
        $plan = $this->buildPlan();
        return [
            'mode' => 'dry_run',
            'would_write' => false,
            'report' => $plan['report'],
            'planned_employee_count' => count($plan['employees']),
            'source_hashes' => $this->sourceFieldHashes(),
            'counts' => $this->snapshotCounts(),
        ];
    }

    /**
     * @return array{report:array,counts:array,source_hashes_before:array,source_hashes_after:array}
     */
    public function migrate(bool $allowOverwriteProfile = false): array
    {
        $beforeHashes = $this->sourceFieldHashes();
        $beforeCounts = $this->snapshotCounts();
        $plan = $this->buildPlan();

        Db::startTrans();
        try {
            $phoneToId = $this->upsertEmployees($plan['employees'], $plan['report'], $allowOverwriteProfile);
            $this->backfillLinks($phoneToId, $plan['report']);
            // 负责人表不写入
            Db::commit();
        } catch (\Throwable $e) {
            Db::rollback();
            throw $e;
        }

        $afterHashes = $this->sourceFieldHashes();
        $report = $plan['report'];
        $report['MIGRATION_SUMMARY']['mode'] = 'migrate';
        $report['MIGRATION_SUMMARY']['employee_count'] = (int)Db::name('employee')->count();
        $report['MIGRATION_SUMMARY']['leader_count'] = (int)Db::name('organization_leader')->count();

        return [
            'mode' => 'migrate',
            'report' => $report,
            'counts_before' => $beforeCounts,
            'counts_after' => $this->snapshotCounts(),
            'source_hashes_before' => $beforeHashes,
            'source_hashes_after' => $afterHashes,
            'phone_to_employee_id' => $phoneToId,
        ];
    }

    /**
     * @return array{ok:bool,errors:array,report:array,counts:array}
     */
    public function reconcile(array $expected = []): array
    {
        $errors = [];
        $expectedEmployees = (int)($expected['employees'] ?? 125);
        $expectedStaff = (int)($expected['staff_effective'] ?? 109);
        $expectedOrgAdmin = (int)($expected['org_admin'] ?? 25);
        $expectedUidConflicts = (int)($expected['uid_multi_phone'] ?? 4);
        $expectedLeaders = (int)($expected['leaders'] ?? 0);
        $checkSafeUid = (bool)($expected['check_safe_uid'] ?? true);

        $empById = [];
        foreach (Db::name('employee')->select()->toArray() as $e) {
            $empById[(int)$e['id']] = $e;
        }
        $empCount = count($empById);
        if ($empCount !== $expectedEmployees) {
            $errors[] = "employee_count={$empCount} expected={$expectedEmployees}";
        }

        $phoneDup = (int)Db::query(
            'SELECT COUNT(*) c FROM (SELECT phone FROM eb_employee GROUP BY phone HAVING COUNT(*)>1) t'
        )[0]['c'] ?? 0;
        if ($phoneDup > 0) {
            $errors[] = "employee_phone_dup_groups={$phoneDup}";
        }

        $badPhone = 0;
        foreach ($empById as $e) {
            if (!preg_match(self::PHONE_REGEX, (string)$e['phone'])) {
                $badPhone++;
            }
        }
        if ($badPhone > 0) {
            $errors[] = "employee_bad_phone={$badPhone}";
        }

        $staffOk = 0;
        foreach (Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->select()->toArray() as $s) {
            $eid = (int)($s['employee_id'] ?? 0);
            $phone = (string)($s['phone'] ?? '');
            if ($eid <= 0) {
                $errors[] = 'staff_missing_employee_id id=' . (int)$s['id'];
                continue;
            }
            if (!isset($empById[$eid])) {
                $errors[] = 'staff_orphan_employee_id id=' . (int)$s['id'] . ' employee_id=' . $eid;
                continue;
            }
            if ((string)$empById[$eid]['phone'] !== $phone) {
                $errors[] = 'staff_phone_mismatch id=' . (int)$s['id']
                    . ' staff_phone=' . $phone
                    . ' employee_phone=' . (string)$empById[$eid]['phone'];
                continue;
            }
            $staffOk++;
        }
        if ($staffOk !== $expectedStaff) {
            $errors[] = "staff_linked_ok={$staffOk} expected={$expectedStaff}";
        }

        $adminLinked = 0;
        foreach (Db::name('system_admin')->where('is_del', 0)->select()->toArray() as $a) {
            $phone = (string)($a['phone'] ?? '');
            $eid = (int)($a['employee_id'] ?? 0);
            if ($phone === '' || !preg_match(self::PHONE_REGEX, $phone)) {
                if ($eid > 0) {
                    $errors[] = 'system_account_has_employee_id id=' . (int)$a['id'];
                }
                continue;
            }
            if ($eid <= 0) {
                $errors[] = 'admin_missing_employee_id id=' . (int)$a['id'];
                continue;
            }
            if (!isset($empById[$eid])) {
                $errors[] = 'admin_orphan_employee_id id=' . (int)$a['id'] . ' employee_id=' . $eid;
                continue;
            }
            if ((string)$empById[$eid]['phone'] !== $phone) {
                $errors[] = 'admin_phone_mismatch id=' . (int)$a['id']
                    . ' admin_phone=' . $phone
                    . ' employee_phone=' . (string)$empById[$eid]['phone'];
                continue;
            }
            $adminLinked++;
        }

        $oaRows = Db::name('organization_admin')->where('is_del', 0)->select()->toArray();
        if (count($oaRows) !== $expectedOrgAdmin) {
            $errors[] = 'org_admin_count=' . count($oaRows) . ' expected=' . $expectedOrgAdmin;
        }
        $oaOk = 0;
        foreach ($oaRows as $oa) {
            $eid = (int)($oa['employee_id'] ?? 0);
            $phone = (string)($oa['phone'] ?? '');
            if ($eid <= 0) {
                $errors[] = 'org_admin_missing_employee_id id=' . (int)$oa['id'];
                continue;
            }
            if (!isset($empById[$eid])) {
                $errors[] = 'org_admin_orphan_employee_id id=' . (int)$oa['id'] . ' employee_id=' . $eid;
                continue;
            }
            if ((string)$empById[$eid]['phone'] !== $phone) {
                $errors[] = 'org_admin_phone_mismatch id=' . (int)$oa['id'];
                continue;
            }
            $ra = Db::name('system_region_agent')->where('id', (int)$oa['legacy_agent_id'])->where('is_del', 0)->find();
            $ad = Db::name('system_admin')->where('id', (int)$oa['admin_id'])->where('is_del', 0)->find();
            if (!$ra || !$ad) {
                $errors[] = 'org_admin_chain_broken id=' . (int)$oa['id'];
                continue;
            }
            if ($phone !== (string)$ra['phone'] || $phone !== (string)$ad['phone']) {
                $errors[] = 'org_admin_chain_phone_mismatch id=' . (int)$oa['id'];
                continue;
            }
            if ((int)($ad['employee_id'] ?? 0) !== $eid) {
                $errors[] = 'org_admin_admin_employee_mismatch id=' . (int)$oa['id'];
                continue;
            }
            $oaOk++;
        }
        if ($oaOk !== $expectedOrgAdmin) {
            $errors[] = "org_admin_linked_ok={$oaOk} expected={$expectedOrgAdmin}";
        }

        $leaderCount = (int)Db::name('organization_leader')->count();
        if ($leaderCount !== $expectedLeaders) {
            $errors[] = "leader_count={$leaderCount} expected={$expectedLeaders}";
        }

        // 只 buildPlan 一次（避免重复扫 staffRows）
        $plan = $this->buildPlan();
        $conflictUids = [];
        foreach ($plan['report']['UID_MULTI_PHONE'] as $row) {
            $conflictUids[] = (int)$row['uid'];
        }
        sort($conflictUids);
        if (count($conflictUids) !== $expectedUidConflicts) {
            $errors[] = 'uid_multi_phone_groups=' . count($conflictUids) . ' expected=' . $expectedUidConflicts;
        }
        foreach ($conflictUids as $uid) {
            $hit = (int)Db::name('employee')->where('uid', $uid)->count();
            if ($hit > 0) {
                $errors[] = "conflict_uid_written_to_employee uid={$uid}";
            }
        }

        if ($checkSafeUid) {
            foreach ($plan['employees'] as $phone => $emp) {
                if ($emp['uid'] === null || (int)$emp['uid'] <= 0) {
                    continue;
                }
                $uid = (int)$emp['uid'];
                $row = Db::name('employee')->where('phone', $phone)->find();
                if (!$row || (int)($row['uid'] ?? 0) !== $uid) {
                    $errors[] = "safe_uid_missing phone={$phone} uid={$uid}";
                }
            }
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'report' => $plan['report'],
            'counts' => $this->snapshotCounts(),
            'conflict_uids' => $conflictUids,
            'staff_linked_ok' => $staffOk,
            'admin_linked_ok' => $adminLinked,
            'org_admin_linked_ok' => $oaOk,
        ];
    }

    /**
     * 来源业务字段全量确定性哈希（不含 employee_id；报告不含账号/密码原文）
     * @return array{system_store_staff:array,system_admin:array,organization_admin:array}
     */
    public function sourceFieldHashes(): array
    {
        return [
            'system_store_staff' => $this->hashSourceRows('system_store_staff', [
                'id', 'store_id', 'uid', 'account', 'pwd', 'avatar', 'staff_name', 'phone', 'roles', 'status', 'is_del',
            ]),
            'system_admin' => $this->hashSourceRows('system_admin', [
                'id', 'uid', 'account', 'admin_type', 'relation_id', 'head_pic', 'pwd', 'real_name', 'phone', 'roles', 'status', 'is_del',
            ]),
            'organization_admin' => $this->hashSourceRows('organization_admin', [
                'id', 'org_id', 'name', 'phone', 'admin_id', 'uid', 'legacy_agent_id', 'is_del',
            ]),
        ];
    }

    /**
     * @param list<string> $fields
     * @return array{sha256:string,row_count:int,min_id:int,max_id:int}
     */
    protected function hashSourceRows(string $table, array $fields): array
    {
        $rows = Db::name($table)->field(implode(',', $fields))->order('id', 'asc')->select()->toArray();
        $ctx = hash_init('sha256');
        $minId = 0;
        $maxId = 0;
        $n = 0;
        foreach ($rows as $row) {
            $n++;
            $id = (int)($row['id'] ?? 0);
            if ($n === 1) {
                $minId = $id;
            }
            $maxId = $id;
            $parts = [];
            foreach ($fields as $f) {
                // 密码字段仅参与摘要，不输出原文
                $parts[] = $f . '=' . (string)($row[$f] ?? '');
            }
            hash_update($ctx, implode('|', $parts) . "\n");
        }
        return [
            'sha256' => hash_final($ctx),
            'row_count' => $n,
            'min_id' => $minId,
            'max_id' => $maxId,
        ];
    }

    public function snapshotCounts(): array
    {
        return [
            'employee' => (int)Db::name('employee')->count(),
            'organization_leader' => (int)Db::name('organization_leader')->count(),
            'employee_change_log' => (int)Db::name('employee_change_log')->count(),
            'staff_total' => (int)Db::name('system_store_staff')->count(),
            'staff_effective' => (int)Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->count(),
            'staff_with_employee' => (int)Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->where('employee_id', '>', 0)->count(),
            'admin_not_del' => (int)Db::name('system_admin')->where('is_del', 0)->count(),
            'admin_with_employee' => (int)Db::name('system_admin')->where('is_del', 0)->where('employee_id', '>', 0)->count(),
            'org_admin' => (int)Db::name('organization_admin')->where('is_del', 0)->count(),
            'org_admin_with_employee' => (int)Db::name('organization_admin')->where('is_del', 0)->where('employee_id', '>', 0)->count(),
        ];
    }

    /**
     * @return array{employees:array<string,array>,report:array}
     */
    protected function buildPlan(): array
    {
        $report = [
            'SOURCE_MAPPING' => [],
            'PROFILE_VALUE_CONFLICT' => [],
            'UID_MULTI_PHONE' => [],
            'INVALID_PHONE' => [],
            'SYSTEM_ACCOUNT_NO_EMPLOYEE' => [],
            'MIGRATION_SUMMARY' => [
                'staff_effective' => 0,
                'staff_mapped' => 0,
                'agent_active' => 0,
                'admin_with_phone' => 0,
                'admin_system_no_employee' => 0,
                'org_admin' => 0,
                'planned_employees' => 0,
                'uid_multi_phone_groups' => 0,
                'profile_conflicts' => 0,
                'invalid_phones' => 0,
            ],
        ];

        /** @var array<string, array{sources:list<array>, names:list<array>, avatars:list<array>, uids:list<int>, status:int}> $byPhone */
        $byPhone = [];

        $staffRows = Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->select()->toArray();
        $report['MIGRATION_SUMMARY']['staff_effective'] = count($staffRows);
        foreach ($staffRows as $row) {
            $this->ingestSource($byPhone, $report, 'system_store_staff', (int)$row['id'], (string)$row['phone'], [
                'name' => (string)($row['staff_name'] ?? ''),
                'avatar' => (string)($row['avatar'] ?? ''),
                'uid' => (int)($row['uid'] ?? 0),
                'priority' => 1,
                'status' => 1,
            ]);
        }

        $agents = Db::name('system_region_agent')->where('is_del', 0)->select()->toArray();
        $report['MIGRATION_SUMMARY']['agent_active'] = count($agents);
        foreach ($agents as $row) {
            $name = trim((string)($row['nickname'] ?? ''));
            if ($name === '') {
                $name = trim((string)($row['name'] ?? ''));
            }
            $this->ingestSource($byPhone, $report, 'system_region_agent', (int)$row['id'], (string)$row['phone'], [
                'name' => $name,
                'avatar' => '',
                'uid' => 0,
                'priority' => 2,
                'status' => 1,
            ]);
        }

        $oaRows = Db::name('organization_admin')->where('is_del', 0)->select()->toArray();
        $report['MIGRATION_SUMMARY']['org_admin'] = count($oaRows);
        foreach ($oaRows as $row) {
            $this->ingestSource($byPhone, $report, 'organization_admin', (int)$row['id'], (string)$row['phone'], [
                'name' => (string)($row['name'] ?? ''),
                'avatar' => '',
                'uid' => (int)($row['uid'] ?? 0),
                'priority' => 3,
                'status' => 1,
            ]);
        }

        $admins = Db::name('system_admin')->where('is_del', 0)->select()->toArray();
        foreach ($admins as $row) {
            $phone = (string)($row['phone'] ?? '');
            if ($phone === '' || !preg_match(self::PHONE_REGEX, $phone) || $phone !== trim($phone)) {
                $report['SYSTEM_ACCOUNT_NO_EMPLOYEE'][] = [
                    'source' => 'system_admin',
                    'source_id' => (int)$row['id'],
                    'account' => '(redacted)',
                    'phone' => $phone === '' ? '' : '(invalid)',
                    'reason' => 'root_or_invalid_phone_allow_null_employee_id',
                ];
                $report['MIGRATION_SUMMARY']['admin_system_no_employee']++;
                if ($phone !== '') {
                    $this->ingestSource($byPhone, $report, 'system_admin', (int)$row['id'], $phone, [
                        'name' => '',
                        'avatar' => '',
                        'uid' => 0,
                        'priority' => 4,
                        'status' => 1,
                    ]);
                    // ingestSource 已记 INVALID；此处避免再归并——ingest 对非法直接 return
                }
                continue;
            }
            $report['MIGRATION_SUMMARY']['admin_with_phone']++;
            $name = trim((string)($row['real_name'] ?? ''));
            if ($name === '') {
                $name = trim((string)($row['account'] ?? ''));
            }
            $this->ingestSource($byPhone, $report, 'system_admin', (int)$row['id'], $phone, [
                'name' => $name,
                'avatar' => (string)($row['head_pic'] ?? ''),
                'uid' => (int)($row['uid'] ?? 0),
                'priority' => 4,
                'status' => (int)($row['status'] ?? 1) === 1 ? 1 : 0,
            ]);
        }

        // UID 冲突：同一 UID 对应多个手机号
        $uidToPhones = [];
        foreach ($byPhone as $phone => $bucket) {
            foreach (array_unique($bucket['uids']) as $uid) {
                if ($uid <= 0) {
                    continue;
                }
                $uidToPhones[$uid][$phone] = true;
            }
        }
        $conflictUids = [];
        foreach ($uidToPhones as $uid => $phonesMap) {
            $phones = array_keys($phonesMap);
            if (count($phones) > 1) {
                $conflictUids[(int)$uid] = $phones;
                $sources = [];
                foreach ($phones as $p) {
                    foreach ($byPhone[$p]['sources'] as $s) {
                        if ((int)($s['uid'] ?? 0) === (int)$uid) {
                            $sources[] = $s;
                        }
                    }
                }
                $report['UID_MULTI_PHONE'][] = [
                    'uid' => (int)$uid,
                    'phones' => $phones,
                    'sources' => $sources,
                ];
            }
        }
        $report['MIGRATION_SUMMARY']['uid_multi_phone_groups'] = count($conflictUids);

        $employees = [];
        foreach ($byPhone as $phone => $bucket) {
            $picked = $this->pickProfile($bucket, $report, $phone);
            $uid = $this->resolveUidForPhone($phone, $bucket['uids'], $conflictUids, $uidToPhones);
            $employees[$phone] = [
                'phone' => $phone,
                'name' => $picked['name'],
                'avatar' => $picked['avatar'],
                'avatar_type' => $picked['avatar_type'],
                'uid' => $uid,
                'status' => $bucket['status'] ?? 1,
            ];
            foreach ($bucket['sources'] as $s) {
                $report['SOURCE_MAPPING'][] = [
                    'source' => $s['source'],
                    'source_id' => $s['source_id'],
                    'phone' => $phone,
                    'uid' => (int)($s['uid'] ?? 0),
                    'employee_phone' => $phone,
                    // employee_id 在 migrate 后回填到报告副本
                ];
            }
        }

        $report['MIGRATION_SUMMARY']['planned_employees'] = count($employees);
        $report['MIGRATION_SUMMARY']['staff_mapped'] = $report['MIGRATION_SUMMARY']['staff_effective'];
        $report['MIGRATION_SUMMARY']['profile_conflicts'] = count($report['PROFILE_VALUE_CONFLICT']);

        return ['employees' => $employees, 'report' => $report];
    }

    protected function ingestSource(array &$byPhone, array &$report, string $source, int $sourceId, string $phoneRaw, array $meta): void
    {
        // 禁止静默 trim：原值必须原样符合标准手机号（含不得有首尾/中间空白）
        $phone = (string)$phoneRaw;
        if ($phone === '' || !preg_match(self::PHONE_REGEX, $phone) || $phone !== trim($phone)) {
            $reason = 'not_strict_cn_mobile';
            if ($phone !== trim($phone) || preg_match('/\s/', $phone)) {
                $reason = 'whitespace_or_padding_not_allowed';
            } elseif (strpos($phone, '-') !== false || strpos($phone, '+') !== false) {
                $reason = 'separator_or_country_code_not_allowed';
            }
            $report['INVALID_PHONE'][] = [
                'source' => $source,
                'source_id' => $sourceId,
                'phone_raw' => $phoneRaw,
                'reason' => $reason,
            ];
            $report['MIGRATION_SUMMARY']['invalid_phones']++;
            return;
        }
        if (!isset($byPhone[$phone])) {
            $byPhone[$phone] = [
                'sources' => [],
                'names' => [],
                'avatars' => [],
                'uids' => [],
                'status' => 1,
            ];
        }
        $uid = (int)($meta['uid'] ?? 0);
        $byPhone[$phone]['sources'][] = [
            'source' => $source,
            'source_id' => $sourceId,
            'phone' => $phone,
            'uid' => $uid,
            'name' => (string)($meta['name'] ?? ''),
            'avatar' => (string)($meta['avatar'] ?? ''),
            'priority' => (int)($meta['priority'] ?? 99),
        ];
        $byPhone[$phone]['names'][] = [
            'value' => (string)($meta['name'] ?? ''),
            'priority' => (int)($meta['priority'] ?? 99),
            'source' => $source,
            'source_id' => $sourceId,
        ];
        $byPhone[$phone]['avatars'][] = [
            'value' => (string)($meta['avatar'] ?? ''),
            'priority' => (int)($meta['priority'] ?? 99),
            'source' => $source,
            'source_id' => $sourceId,
        ];
        if ($uid > 0) {
            $byPhone[$phone]['uids'][] = $uid;
        }
        if ((int)($meta['status'] ?? 1) === 0) {
            // 仅当全部来源离职才置 0；有在职来源保持 1
        } else {
            $byPhone[$phone]['status'] = 1;
        }
    }

    protected function pickProfile(array $bucket, array &$report, string $phone): array
    {
        $name = $this->pickByPriority($bucket['names'], '');
        $avatarRaw = $this->pickByPriority($bucket['avatars'], '');
        $avatar = $avatarRaw !== '' ? $avatarRaw : self::DEFAULT_AVATAR;
        $avatarType = $avatarRaw !== '' ? 3 : self::DEFAULT_AVATAR_TYPE;

        $nameValues = [];
        foreach ($bucket['names'] as $n) {
            $v = trim((string)$n['value']);
            if ($v !== '') {
                $nameValues[$v] = true;
            }
        }
        if (count($nameValues) > 1) {
            $report['PROFILE_VALUE_CONFLICT'][] = [
                'phone' => $phone,
                'field' => 'name',
                'candidates' => $bucket['names'],
                'final' => $name,
                'reason' => 'priority_staff_agent_orgadmin_admin',
            ];
        }
        $avatarValues = [];
        foreach ($bucket['avatars'] as $a) {
            $v = trim((string)$a['value']);
            if ($v !== '') {
                $avatarValues[$v] = true;
            }
        }
        if (count($avatarValues) > 1) {
            $report['PROFILE_VALUE_CONFLICT'][] = [
                'phone' => $phone,
                'field' => 'avatar',
                'candidates' => $bucket['avatars'],
                'final' => $avatar,
                'reason' => 'priority_staff_agent_orgadmin_admin_default_male',
            ];
        }
        if ($name === '') {
            $name = '未命名员工';
        }
        return [
            'name' => $name,
            'avatar' => $avatar,
            'avatar_type' => $avatarType,
        ];
    }

    protected function pickByPriority(array $items, string $default): string
    {
        usort($items, function ($a, $b) {
            return ((int)$a['priority']) <=> ((int)$b['priority']);
        });
        foreach ($items as $item) {
            $v = trim((string)($item['value'] ?? ''));
            if ($v !== '') {
                return $v;
            }
        }
        return $default;
    }

    /**
     * @param array<int, list<string>> $conflictUids
     * @param array<int, array<string,bool>> $uidToPhones
     */
    protected function resolveUidForPhone(string $phone, array $uids, array $conflictUids, array $uidToPhones): ?int
    {
        $uids = array_values(array_unique(array_filter(array_map('intval', $uids))));
        if ($uids === []) {
            return null;
        }
        if (count($uids) > 1) {
            return null;
        }
        $uid = (int)$uids[0];
        if (isset($conflictUids[$uid])) {
            return null;
        }
        // PHP 会把纯数字手机号 array key 转成 int，必须转成字符串再比
        $phonesForUid = array_map('strval', array_keys($uidToPhones[$uid] ?? [$phone => true]));
        if (count($phonesForUid) !== 1 || $phonesForUid[0] !== (string)$phone) {
            return null;
        }
        return $uid;
    }

    /**
     * @param array<string,array> $employees
     * @return array<string,int> phone => employee_id
     */
    protected function upsertEmployees(array $employees, array &$report, bool $allowOverwriteProfile): array
    {
        $now = time();
        $map = [];
        foreach ($employees as $phone => $emp) {
            $existing = Db::name('employee')->where('phone', $phone)->find();
            if ($existing) {
                $eid = (int)$existing['id'];
                $diff = [];
                foreach (['name', 'avatar', 'avatar_type', 'uid', 'status'] as $f) {
                    $newVal = $emp[$f] ?? null;
                    $oldVal = $existing[$f] ?? null;
                    if ($f === 'uid') {
                        $newVal = $newVal === null ? null : (int)$newVal;
                        $oldVal = $oldVal === null || $oldVal === '' ? null : (int)$oldVal;
                    } elseif ($f === 'avatar_type' || $f === 'status') {
                        $newVal = (int)$newVal;
                        $oldVal = (int)$oldVal;
                    } else {
                        $newVal = (string)$newVal;
                        $oldVal = (string)$oldVal;
                    }
                    if ($newVal !== $oldVal) {
                        $diff[$f] = ['old' => $oldVal, 'new' => $newVal];
                    }
                }
                if ($diff !== []) {
                    if (!$allowOverwriteProfile) {
                        // 默认模式：不 UPDATE（含不补写 NULL uid、不改 update_time）
                        $report['PROFILE_VALUE_CONFLICT'][] = [
                            'phone' => $phone,
                            'field' => 'existing_employee_guard',
                            'employee_id' => $eid,
                            'diff' => $diff,
                            'final' => 'keep_existing',
                            'reason' => 'refuse_silent_overwrite_on_rerun',
                        ];
                    } else {
                        Db::name('employee')->where('id', $eid)->update([
                            'name' => $emp['name'],
                            'avatar' => $emp['avatar'],
                            'avatar_type' => $emp['avatar_type'],
                            'uid' => $emp['uid'],
                            'status' => $emp['status'],
                            'update_time' => $now,
                        ]);
                    }
                }
                // 完全一致：零 UPDATE
                $map[$phone] = $eid;
                continue;
            }
            $eid = (int)Db::name('employee')->insertGetId([
                'name' => $emp['name'],
                'phone' => $phone,
                'avatar' => $emp['avatar'] !== '' ? $emp['avatar'] : self::DEFAULT_AVATAR,
                'avatar_type' => (int)$emp['avatar_type'],
                'uid' => $emp['uid'],
                'status' => (int)$emp['status'],
                'is_del' => 0,
                'add_time' => $now,
                'update_time' => $now,
            ]);
            $map[$phone] = $eid;
        }

        // 回填 SOURCE_MAPPING.employee_id
        foreach ($report['SOURCE_MAPPING'] as &$row) {
            $p = (string)$row['phone'];
            $row['employee_id'] = (int)($map[$p] ?? 0);
        }
        unset($row);

        return $map;
    }

    /**
     * @param array<string,int> $phoneToId
     */
    protected function backfillLinks(array $phoneToId, array &$report): void
    {
        foreach (Db::name('system_store_staff')->where('status', 1)->where('is_del', 0)->select()->toArray() as $row) {
            $phone = (string)$row['phone'];
            if (!isset($phoneToId[$phone])) {
                continue;
            }
            $eid = $phoneToId[$phone];
            $cur = $row['employee_id'] ?? null;
            if ($cur !== null && (int)$cur > 0 && (int)$cur !== $eid) {
                throw new \RuntimeException('staff employee_id conflict id=' . (int)$row['id'] . " cur={$cur} new={$eid}");
            }
            if ((int)$cur !== $eid) {
                Db::name('system_store_staff')->where('id', (int)$row['id'])->update(['employee_id' => $eid]);
            }
        }

        foreach (Db::name('system_admin')->where('is_del', 0)->select()->toArray() as $row) {
            $phone = (string)($row['phone'] ?? '');
            if ($phone === '' || !preg_match(self::PHONE_REGEX, $phone)) {
                if ($row['employee_id'] !== null && (int)$row['employee_id'] > 0) {
                    // 保持：系统账号不应有 employee_id；若有则报错
                    throw new \RuntimeException('system admin unexpectedly has employee_id id=' . (int)$row['id']);
                }
                continue;
            }
            if (!isset($phoneToId[$phone])) {
                throw new \RuntimeException('admin phone missing employee plan phone=' . $phone);
            }
            $eid = $phoneToId[$phone];
            $cur = $row['employee_id'] ?? null;
            if ($cur !== null && (int)$cur > 0 && (int)$cur !== $eid) {
                throw new \RuntimeException('admin employee_id conflict id=' . (int)$row['id']);
            }
            if ((int)$cur !== $eid) {
                Db::name('system_admin')->where('id', (int)$row['id'])->update(['employee_id' => $eid]);
            }
        }

        foreach (Db::name('organization_admin')->where('is_del', 0)->select()->toArray() as $row) {
            $phone = (string)$row['phone'];
            if (!isset($phoneToId[$phone])) {
                throw new \RuntimeException('org_admin phone missing employee plan phone=' . $phone);
            }
            $eid = $phoneToId[$phone];
            $cur = $row['employee_id'] ?? null;
            if ($cur !== null && (int)$cur > 0 && (int)$cur !== $eid) {
                throw new \RuntimeException('org_admin employee_id conflict id=' . (int)$row['id']);
            }
            if ((int)$cur !== $eid) {
                Db::name('organization_admin')->where('id', (int)$row['id'])->update(['employee_id' => $eid]);
            }
        }
    }

    /** @return list<int> */
    protected function detectUidMultiPhoneUids(): array
    {
        $plan = $this->buildPlan();
        $uids = [];
        foreach ($plan['report']['UID_MULTI_PHONE'] as $row) {
            $uids[] = (int)$row['uid'];
        }
        sort($uids);
        return $uids;
    }

    /** @return array<string,int> */
    protected function safeUidByPhone(): array
    {
        $plan = $this->buildPlan();
        $out = [];
        foreach ($plan['employees'] as $phone => $emp) {
            if ($emp['uid'] !== null && (int)$emp['uid'] > 0) {
                $out[$phone] = (int)$emp['uid'];
            }
        }
        return $out;
    }
}
