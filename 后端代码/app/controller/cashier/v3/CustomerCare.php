<?php
namespace app\controller\cashier\v3;

use app\controller\cashier\AuthController;
use app\services\customer\care\integration\CustomerCareActionInputMapper;
use app\services\customer\care\integration\CustomerCareWorkbenchActionAdapter;
use think\facade\App;
use think\facade\Db;

/** 门店 PC 客情适配器；业务范围只从强制门店会话生成。 */
class CustomerCare extends AuthController
{
    /** @var CustomerCareWorkbenchActionAdapter */
    private $care;

    public function __construct(App $app, CustomerCareWorkbenchActionAdapter $care)
    {
        parent::__construct($app);
        $this->care = $care;
    }

    public function action()
    {
        $payload = $this->request->post();
        $payload = is_array($payload) ? $payload : [];
        $action = trim((string)($payload['action'] ?? ''));
        if ($action === 'query-care-members') {
            return $this->success('ok', ['result' => ['status' => 'success', 'code' => '', 'message' => '会员查询成功。'], 'data' => $this->queryMembers($payload)]);
        }
        $supported = [
            CustomerCareActionInputMapper::QUERY,
            CustomerCareActionInputMapper::CREATE_TASK,
            CustomerCareActionInputMapper::START_TASK,
            CustomerCareActionInputMapper::COMPLETE_TASK,
            CustomerCareActionInputMapper::REASSIGN_TASK,
            CustomerCareActionInputMapper::VOID_TASK,
            CustomerCareActionInputMapper::DELETE_TASK,
            CustomerCareActionInputMapper::CREATE_RECORD,
            CustomerCareActionInputMapper::VOID_RECORD,
        ];
        if (!in_array($action, $supported, true)) {
            return $this->success('ok', ['result' => [
                'status' => 'failed', 'code' => 'CARE_ACTION_NOT_SUPPORTED',
                'message' => '当前客情操作未开放。',
            ]]);
        }
        $context = $this->trustedContext();
        if ($context === null) {
            return $this->success('ok', ['result' => [
                'status' => 'failed', 'code' => 'CARE_OPERATOR_EMPLOYEE_REQUIRED',
                'message' => '当前门店账号尚未绑定统一员工档案，暂不能进入正式客情工作台。',
            ]]);
        }
        $idempotencyKey = trim((string)($payload['idempotencyKey'] ?? ''));
        if ($action !== CustomerCareActionInputMapper::QUERY && $idempotencyKey === '') {
            return $this->success('ok', ['result' => [
                'status' => 'failed', 'code' => 'CARE_IDEMPOTENCY_REQUIRED',
                'message' => '缺少本次客情操作标识，请重试。',
            ]]);
        }
        return $this->success('ok', $this->care->handle(
            $action,
            $context,
            $payload,
            $idempotencyKey,
            (array)($payload['workbenchRequest'] ?? [])
        ));
    }

    private function trustedContext(): ?array
    {
        $staff = Db::name('system_store_staff')->alias('s')
            ->join('employee e', 'e.id=s.employee_id')
            ->where('s.id', (int)$this->cashierId)
            ->where('s.store_id', (int)$this->storeId)
            ->where('s.status', 1)->where('s.is_del', 0)
            ->where('e.status', 1)->where('e.is_del', 0)
            ->field('s.id,s.employee_id,s.staff_name,s.level,s.is_manager,e.name AS employee_name')
            ->find();
        if (!is_array($staff) || (int)($staff['employee_id'] ?? 0) <= 0) return null;
        $store = Db::name('system_store')->where('id', (int)$this->storeId)->where('is_del', 0)->field('id,name')->find();
        $organization = Db::name('organization_store')->alias('os')
            ->join('organization o', 'o.id=os.org_id')
            ->where('os.store_id', (int)$this->storeId)->where('o.is_del', 0)
            ->field('o.id,o.name')->find();
        if (!is_array($store) || !is_array($organization)) return null;
        $isManager = (int)($staff['level'] ?? 1) === 0 || (int)($staff['is_manager'] ?? 0) === 1;
        return [
            'tenantId' => '0',
            'staffId' => (int)$staff['id'], 'employeeId' => (int)$staff['employee_id'],
            'staffName' => trim((string)$staff['staff_name']) ?: (string)$staff['employee_name'],
            'operationStoreId' => (int)$store['id'], 'operationStoreName' => (string)$store['name'],
            'operationOrganizationId' => (string)$organization['id'],
            'operationOrganizationPath' => '/' . (string)$organization['id'],
            'operationOrganizationName' => (string)$organization['name'],
            'allowedBusinessStoreIds' => [(int)$store['id']],
            'businessTimezone' => 'Asia/Shanghai',
            'canViewAllTasks' => $isManager, 'canCreateTask' => true,
            'canCreateRecord' => true, 'canReassign' => $isManager,
            'canViewStatistics' => $isManager,
        ];
    }

    private function queryMembers(array $payload): array
    {
        $keyword = trim((string)($payload['keyword'] ?? ''));
        $page = max(1, (int)($payload['page'] ?? 1));
        $pageSize = min(50, max(1, (int)($payload['pageSize'] ?? 20)));
        $query = Db::name('store_user')->alias('su')
            ->join('user u', 'u.uid=su.uid')
            ->where('su.store_id', (int)$this->storeId)->where('su.status', 1);
        if ($keyword !== '') {
            $like = '%' . addcslashes($keyword, "\\%_") . '%';
            $query->where(function ($where) use ($like) {
                $where->whereLike('u.real_name', $like)
                    ->whereLike('u.nickname', $like, 'OR')
                    ->whereLike('u.phone', $like, 'OR')
                    ->whereLike('u.uid', $like, 'OR');
            });
        }
        $total = (int)(clone $query)->count();
        $rows = $query->field('u.uid,u.real_name,u.nickname,u.phone,su.store_id')
            ->order('u.uid', 'desc')->page($page, $pageSize)->select()->toArray();
        $records = [];
        foreach ((array)$rows as $row) {
            $records[] = [
                'id' => (string)(int)$row['uid'], 'memberId' => (string)(int)$row['uid'],
                'name' => trim((string)$row['real_name']) ?: trim((string)$row['nickname']) ?: '未命名会员',
                'phone' => (string)$row['phone'], 'memberNo' => (string)(int)$row['uid'],
                'status' => '正常', 'selectable' => true,
            ];
        }
        return ['records' => $records, 'total' => $total, 'page' => $page, 'pageSize' => $pageSize];
    }
}
