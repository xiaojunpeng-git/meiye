<?php
namespace app\services\organization;

use app\dao\employee\OrganizationEmployeeDao;
use app\services\BaseServices;
use app\services\employee\EmployeeStaffWriteServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I1 组织直属人员 CRUD（仅总部）
 */
class OrganizationEmployeeServices extends BaseServices
{
    public function __construct(OrganizationEmployeeDao $dao)
    {
        $this->dao = $dao;
    }

    public const MAX_LIMIT = 50;

    public function getList(int $orgId, int $page = 1, int $limit = 20): array
    {
        if ($orgId <= 0) {
            throw new AdminException('组织无效');
        }
        $page = max(1, (int)$page);
        $limit = $limit <= 0 ? 20 : min(self::MAX_LIMIT, (int)$limit);
        $query = Db::name('organization_employee')->alias('oe')
            ->join('employee e', 'e.id = oe.employee_id')
            ->where('oe.org_id', $orgId)
            ->where('oe.is_del', 0)
            ->where('e.is_del', 0)
            ->field('oe.id,oe.org_id,oe.employee_id,oe.job_title,oe.sort,oe.status,oe.source,oe.add_time,oe.update_time,e.name,e.phone,e.avatar,e.status as employee_status');
        $count = (clone $query)->count();
        if ($this->hasColumn('sort')) {
            $query->order('oe.sort', 'asc');
        }
        $list = $query->order('oe.id', 'desc')
            ->page($page, $limit)->select()->toArray();
        return ['list' => $list, 'count' => (int)$count, 'page' => $page, 'limit' => $limit];
    }

    /**
     * @param array{org_id:int,employee_id?:int,phone?:string,name?:string,job_title?:string,sort?:int,status?:int,request_token?:string} $data
     * @param array{use_outer_transaction?:bool} $options 编排外层事务内调用时传 use_outer_transaction=true
     */
    public function save(array $data, array $operatorContext = [], array $options = []): array
    {
        /** @var OrganizationWorkspaceWriteGate $gate */
        $gate = app()->make(OrganizationWorkspaceWriteGate::class);
        $gate->assertCanWrite();

        $runner = function () use ($data, $operatorContext) {
            $orgId = (int)($data['org_id'] ?? 0);
            if ($orgId <= 0) {
                throw new AdminException('请选择组织');
            }
            $org = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->lock(true)->find();
            if (!$org) {
                throw new AdminException('组织不存在');
            }

            /** @var EmployeeStaffWriteServices $write */
            $write = app()->make(EmployeeStaffWriteServices::class);
            $employeeId = (int)($data['employee_id'] ?? 0);
            if ($employeeId > 0) {
                $employee = Db::name('employee')->where('id', $employeeId)->lock(true)->find();
                if (!$employee || (int)($employee['is_del'] ?? 0) === 1) {
                    throw new AdminException('员工不存在');
                }
            } else {
                $phone = $write->assertStrictPhone((string)($data['phone'] ?? ''));
                $name = trim((string)($data['name'] ?? ''));
                if ($name === '') {
                    throw new AdminException('请填写员工姓名');
                }
                $existing = Db::name('employee')->where('phone', $phone)->lock(true)->find();
                if ($existing) {
                    if ((int)($existing['is_del'] ?? 0) === 1) {
                        $restored = $write->restoreDeletedEmployeeByPhone($phone, $operatorContext);
                        if (!$restored) {
                            throw new AdminException('员工主档状态已变化，请重试');
                        }
                        $employeeId = (int)$restored['employee_id'];
                    } else {
                        $employeeId = (int)$existing['id'];
                    }
                } else {
                    $now = time();
                    $employeeId = (int)Db::name('employee')->insertGetId([
                        'name' => $name,
                        'phone' => $phone,
                        'avatar' => EmployeeStaffWriteServices::DEFAULT_AVATAR,
                        'avatar_type' => 1,
                        'uid' => null,
                        'status' => 1,
                        'is_del' => 0,
                        'add_time' => $now,
                        'update_time' => $now,
                    ]);
                }
            }

            $jobTitle = trim((string)($data['job_title'] ?? ''));
            $sort = (int)($data['sort'] ?? 0);
            $status = (int)($data['status'] ?? 1) === 1 ? 1 : 0;
            $now = time();
            $hasSort = $this->hasColumn('sort');
            $hasStatus = $this->hasColumn('status');

            $row = Db::name('organization_employee')
                ->where('org_id', $orgId)
                ->where('employee_id', $employeeId)
                ->lock(true)
                ->find();
            if ($row) {
                $update = [
                    'job_title' => $jobTitle,
                    'is_del' => 0,
                    'update_time' => $now,
                ];
                if ($hasSort) {
                    $update['sort'] = $sort;
                }
                if ($hasStatus) {
                    $update['status'] = $status;
                }
                Db::name('organization_employee')->where('id', (int)$row['id'])->update($update);
                $id = (int)$row['id'];
            } else {
                $insert = [
                    'org_id' => $orgId,
                    'employee_id' => $employeeId,
                    'job_title' => $jobTitle,
                    'source' => 'admin',
                    'is_del' => 0,
                    'add_time' => $now,
                    'update_time' => $now,
                ];
                if ($hasSort) {
                    $insert['sort'] = $sort;
                }
                if ($hasStatus) {
                    $insert['status'] = $status;
                }
                $id = (int)Db::name('organization_employee')->insertGetId($insert);
            }

            Db::name('employee_change_log')->insert([
                'employee_id' => $employeeId,
                'action' => 'organization_employee_save',
                'target_type' => 'organization_employee',
                'target_id' => $id,
                'source' => (string)($operatorContext['source'] ?? 'admin'),
                'before_data' => '',
                'after_data' => json_encode([
                    'org_id' => $orgId,
                    'employee_id' => $employeeId,
                    'job_title' => $jobTitle,
                    'status' => $status,
                ], JSON_UNESCAPED_UNICODE) ?: '',
                'reason' => (string)($operatorContext['reason'] ?? '保存组织直属'),
                'operator_type' => 'admin',
                'operator_id' => (int)($operatorContext['operator_id'] ?? 0),
                'operator_name' => (string)($operatorContext['operator_name'] ?? ''),
                'operator_ip' => (string)($operatorContext['operator_ip'] ?? ''),
                'request_id' => (string)($operatorContext['request_id'] ?? ''),
                'add_time' => $now,
            ]);

            return ['id' => $id, 'employee_id' => $employeeId, 'org_id' => $orgId];
        };
        if (!empty($options['use_outer_transaction'])) {
            return $runner();
        }
        return Db::transaction($runner);
    }

    public function softDelete(int $id, array $operatorContext = []): void
    {
        /** @var OrganizationWorkspaceWriteGate $gate */
        $gate = app()->make(OrganizationWorkspaceWriteGate::class);
        $gate->assertCanWrite();

        Db::transaction(function () use ($id, $operatorContext) {
            $row = Db::name('organization_employee')->where('id', $id)->lock(true)->find();
            if (!$row || (int)($row['is_del'] ?? 0) === 1) {
                throw new AdminException('直属关系不存在');
            }
            $now = time();
            $update = [
                'is_del' => 1,
                'update_time' => $now,
            ];
            if ($this->hasColumn('status')) {
                $update['status'] = 0;
            }
            Db::name('organization_employee')->where('id', $id)->update($update);
            Db::name('employee_change_log')->insert([
                'employee_id' => (int)$row['employee_id'],
                'action' => 'organization_employee_remove',
                'target_type' => 'organization_employee',
                'target_id' => $id,
                'source' => (string)($operatorContext['source'] ?? 'admin'),
                'before_data' => '',
                'after_data' => json_encode(['is_del' => 1], JSON_UNESCAPED_UNICODE) ?: '',
                'reason' => (string)($operatorContext['reason'] ?? '移除组织直属'),
                'operator_type' => 'admin',
                'operator_id' => (int)($operatorContext['operator_id'] ?? 0),
                'operator_name' => (string)($operatorContext['operator_name'] ?? ''),
                'operator_ip' => (string)($operatorContext['operator_ip'] ?? ''),
                'request_id' => (string)($operatorContext['request_id'] ?? ''),
                'add_time' => $now,
            ]);
        });
    }

    protected function hasColumn(string $column): bool
    {
        static $cache = [];
        if (array_key_exists($column, $cache)) {
            return $cache[$column];
        }
        try {
            $cols = Db::query("SHOW COLUMNS FROM `eb_organization_employee` LIKE '" . str_replace("'", '', $column) . "'");
            $cache[$column] = !empty($cols);
        } catch (\Throwable $e) {
            $cache[$column] = false;
        }
        return $cache[$column];
    }
}
