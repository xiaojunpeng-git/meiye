<?php
namespace app\controller\api\v1\merchant;

use app\Request;
use app\services\merchant\MerchantAccessServices;
use app\services\merchant\MerchantCustomerServices;
use app\services\merchant\MerchantCustomerCareServices;
use app\services\merchant\MerchantDataServices;
use app\services\merchant\MerchantHomeServices;
use app\services\system\TrainingDocumentServices;
use app\services\merchant\MerchantYejiServices;
use app\services\merchant\MerchantStoreMetricServices;
use app\services\merchant\MerchantReservationServices;
use app\services\order\StoreDebtServices;
use app\model\order\StoreDebt;

/**
 * 商家业务接口：入口后必须再按功能权限 Guard，禁止仅依赖前端隐藏
 */
class MerchantBiz
{
    protected function access(Request $request): array
    {
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $context = $request->getMore([
            ['active_store_id', 0],
            ['active_role', ''],
        ]);
        /** @var MerchantAccessServices $services */
        $services = app()->make(MerchantAccessServices::class);
        $access = $services->resolveAccess($uid, $context);
        if (!$access['can_enter_merchant']) {
            throw new \think\exception\ValidateException('当前账号暂无商家权限');
        }
        return [$uid, $access, $services];
    }

    public function home(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        // 有 merchant.enter 即可进首页骨架；无 home.view 时服务层返回裁剪结果（配送/客服），禁止全平台聚合
        /** @var MerchantHomeServices $services */
        $services = app()->make(MerchantHomeServices::class);
        return app('json')->success($services->overview($uid, $access));
    }

    /** 商家手机端培训资料列表：当前门店 ∈ scope，按任职角色过滤。 */
    public function trainingDocuments(Request $request)
    {
        [$uid, $access] = $this->access($request);
        [$storeId, $roleIds] = $this->resolveTrainingContext($uid, $access);
        $where = $request->getMore([
            ['page', 1],
            ['limit', 20],
            ['keyword', ''],
            ['category', ''],
        ]);
        /** @var TrainingDocumentServices $services */
        $services = app()->make(TrainingDocumentServices::class);
        return app('json')->success($services->userList('mobile', $storeId, $roleIds, $where));
    }

    /** 商家手机端受控下载：可见性与门店后台同源校验，并写下载审计。 */
    public function trainingDocumentDownload(Request $request, $id = 0)
    {
        [$uid, $access] = $this->access($request);
        $docId = (int)($id ?: $request->param('id', 0));
        if ($docId <= 0) {
            throw new \think\exception\ValidateException('参数错误');
        }
        [$storeId, $roleIds, $accountName] = $this->resolveTrainingContext($uid, $access, true);
        /** @var TrainingDocumentServices $services */
        $services = app()->make(TrainingDocumentServices::class);
        $file = $services->download($docId, 'mobile', $uid, $accountName, $storeId, $roleIds);
        return download($file['file_path'], $file['file_name']);
    }

    /**
     * @return array{0:int,1:array,2?:string} storeId, roleIds[, accountName]
     */
    protected function resolveTrainingContext(int $uid, array $access, bool $withName = false): array
    {
        $storeId = (int)($access['active_store_id'] ?? 0);
        $scope = array_values(array_unique(array_filter(array_map('intval', (array)($access['scope_store_ids'] ?? [])))));
        if ($storeId <= 0) {
            throw new \think\exception\ValidateException('请先选择门店');
        }
        // scope 为空也拒绝：禁止用任意 active_store_id 绕过授权门店
        if (!$scope || !in_array($storeId, $scope, true)) {
            throw new \think\exception\ValidateException('当前门店不在资料访问范围内');
        }
        $roleIds = [];
        $accountName = '商家用户';
        try {
            /** @var \app\services\store\SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(\app\services\store\SystemStoreStaffServices::class);
            $staffRow = $staffServices->getStaffInfoByUid($uid, $storeId);
            $staff = $staffRow ? (is_array($staffRow) ? $staffRow : $staffRow->toArray()) : null;
            if ($staff) {
                $rolesRaw = $staff['roles'] ?? [];
                if (is_string($rolesRaw)) {
                    $rolesRaw = $rolesRaw === '' ? [] : explode(',', $rolesRaw);
                }
                $roleIds = array_values(array_unique(array_filter(array_map('intval', (array)$rolesRaw))));
                $accountName = (string)($staff['staff_name'] ?? $accountName);
            }
        } catch (\Throwable $e) {
            $roleIds = [];
        }
        return $withName ? [$storeId, $roleIds, $accountName] : [$storeId, $roleIds];
    }

    public function customerSegments(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.customer.view'], '暂无客户查看权限');
        /** @var MerchantCustomerServices $services */
        $services = app()->make(MerchantCustomerServices::class);
        return app('json')->success(['list' => $services->segments($uid, $access)]);
    }

    public function customerMineSummary(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.customer.view'], '暂无客户查看权限');
        /** @var MerchantCustomerServices $services */
        $services = app()->make(MerchantCustomerServices::class);
        return app('json')->success($services->mineSummary($uid, $access));
    }

    public function customerCreate(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.customer.create'], '暂无新增客户权限');
        $data = $request->postMore([
            ['phone', ''],
            ['nickname', ''],
            ['real_name', ''],
            ['sex', 0],
            ['birthday', ''],
            ['mark', ''],
        ]);
        /** @var MerchantCustomerServices $services */
        $services = app()->make(MerchantCustomerServices::class);
        return app('json')->success($services->createCustomer($uid, $access, $data));
    }

    public function customerList(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.customer.view'], '暂无客户查看权限');
        $filter = $request->getMore([
            ['page', 1],
            ['limit', 20],
            ['keyword', ''],
            ['nickname', ''],
            ['birthday_type', 0],
            ['now_money_peice', ''],
            ['sex', ''],
            ['field_key', ''],
            ['segment', ''],
            ['start_date', ''],
            ['end_date', ''],
        ]);
        /** @var MerchantCustomerServices $services */
        $services = app()->make(MerchantCustomerServices::class);
        return app('json')->success($services->listCustomers($uid, $access, $filter));
    }

    public function customerDetail(Request $request, $uid = 0)
    {
        [$operatorUid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.customer.view'], '暂无客户查看权限');
        $targetUid = (int)($uid ?: $request->param('uid', 0));
        /** @var MerchantCustomerServices $services */
        $services = app()->make(MerchantCustomerServices::class);
        return app('json')->success($services->customerDetail($targetUid, $access));
    }

    public function customerOrders(Request $request)
    {
        [$operatorUid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.customer.view'], '暂无客户查看权限');
        $filter = $request->getMore([
            ['uid', 0],
            ['show_type', 1],
            ['page', 1],
            ['limit', 20],
        ]);
        /** @var MerchantCustomerServices $services */
        $services = app()->make(MerchantCustomerServices::class);
        return app('json')->success($services->customerOrders((int)$filter['uid'], $access, $filter));
    }

    public function customerUpdate(Request $request)
    {
        [$operatorUid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.customer.edit'], '暂无客户编辑权限');
        $data = $request->postMore([
            ['uid', 0],
            ['real_name', ''],
            ['birthday', ''],
            ['sex', 0],
            ['addres', ''],
            ['mark', ''],
        ]);
        $targetUid = (int)($data['uid'] ?? 0);
        /** @var MerchantCustomerServices $services */
        $services = app()->make(MerchantCustomerServices::class);
        $services->updateCustomer($targetUid, $access, $data);
        return app('json')->success('保存成功');
    }

    /** 手机端客情任务只读工作台，任务权威仍由共享客情服务提供。 */
    public function customerCareWorkbench(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.customer.view'], '暂无客情查看权限');
        $input = $request->getMore([
            ['bucket', 'today'],
            ['scope', 'my'],
        ]);
        /** @var MerchantCustomerCareServices $services */
        $services = app()->make(MerchantCustomerCareServices::class);
        return app('json')->success($services->workbench($uid, $access, $input));
    }

    public function dataBusiness(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        // 整店/区域经营，或本人数据入口（本人口径未确认时服务层返回 developing）
        $accessServices->requireAnyPermission(
            $access,
            ['merchant.data.store', 'merchant.data.region', 'merchant.data.self'],
            '暂无经营数据查看权限'
        );
        $filter = $request->getMore([
            ['date_type', 'today'],
            ['start_date', date('Y-m-d')],
            ['end_date', date('Y-m-d')],
        ]);
        /** @var MerchantDataServices $services */
        $services = app()->make(MerchantDataServices::class);
        $access['uid'] = $uid;
        return app('json')->success($services->businessOverview($access, $filter));
    }

    public function dataCustomer(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.customer.view'], '暂无客户分析查看权限');
        $filter = $request->getMore([
            ['date_type', 'today'],
            ['start_date', date('Y-m-d')],
            ['end_date', date('Y-m-d')],
        ]);
        /** @var MerchantDataServices $services */
        $services = app()->make(MerchantDataServices::class);
        return app('json')->success($services->customerOverview($access, $filter));
    }

    public function dataStaffStats(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        // 整店聚合：仅店长/区域；禁止 data.self 读取本店新增客户/服务次数/预约单数
        $accessServices->requireAnyPermission(
            $access,
            ['merchant.data.store', 'merchant.data.region'],
            '暂无员工统计查看权限'
        );
        if (!$accessServices->canAggregateStoreMetrics($access)) {
            return app('json')->fail('暂无员工统计查看权限');
        }
        $filter = $request->getMore([
            ['date_type', 'today'],
            ['start_date', date('Y-m-d')],
            ['end_date', date('Y-m-d')],
        ]);
        /** @var MerchantDataServices $services */
        $services = app()->make(MerchantDataServices::class);
        return app('json')->success($services->staffStatistics($access, $filter));
    }

    /**
     * 本人业绩概览：服务端解析 staff_id，拒绝客户端 staff_id
     */
    public function yejiSelf(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.data.self'], '暂无本人业绩查看权限');
        // 显式丢弃客户端 staff_id，防止越权
        $filter = $request->getMore([
            ['date_type', 'today'],
            ['start_date', date('Y-m-d')],
            ['end_date', date('Y-m-d')],
        ]);
        unset($filter['staff_id']);
        /** @var MerchantYejiServices $services */
        $services = app()->make(MerchantYejiServices::class);
        return app('json')->success($services->selfOverview($uid, $access, $filter));
    }

    /**
     * 本人业绩明细列表：强制本人 staff_id
     */
    public function yejiSelfDetail(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $accessServices->requirePermissions($access, ['merchant.data.self'], '暂无本人业绩查看权限');
        $filter = $request->getMore([
            ['date_type', 'today'],
            ['start_date', date('Y-m-d')],
            ['end_date', date('Y-m-d')],
            ['sum_type', 1],
            ['page', 1],
            ['limit', 20],
        ]);
        // 拒绝信任请求中的 staff_id
        if ($request->param('staff_id') !== null && $request->param('staff_id') !== '') {
            // 不抛错也可，但明确拒绝更清晰
            throw new \think\exception\ValidateException('不允许指定员工，仅可查看本人业绩');
        }
        unset($filter['staff_id']);
        /** @var MerchantYejiServices $services */
        $services = app()->make(MerchantYejiServices::class);
        return app('json')->success($services->selfDetail($uid, $access, $filter));
    }

    /**
     * 店级现金业绩明细：与 homeStatics 现金项同口径
     */
    public function metricCashDetail(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $filter = $request->getMore([
            ['date_type', 'today'],
            ['start_date', date('Y-m-d')],
            ['end_date', date('Y-m-d')],
            ['page', 1],
            ['limit', 20],
        ]);
        /** @var MerchantStoreMetricServices $services */
        $services = app()->make(MerchantStoreMetricServices::class);
        return app('json')->success($services->cashDetail($access, $filter));
    }

    /**
     * 店级实收业绩明细：逐店 max(0,现金−分成) 再求和，与 homeStatics 同口径
     */
    public function metricActualDetail(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $filter = $request->getMore([
            ['date_type', 'today'],
            ['start_date', date('Y-m-d')],
            ['end_date', date('Y-m-d')],
            ['page', 1],
            ['limit', 20],
        ]);
        /** @var MerchantStoreMetricServices $services */
        $services = app()->make(MerchantStoreMetricServices::class);
        return app('json')->success($services->actualDetail($access, $filter));
    }

    /**
     * 店级消耗金额明细：activeYeji + 旧店耗卡，与 homeStatics 消耗项同口径
     */
    public function metricConsumeDetail(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        $filter = $request->getMore([
            ['date_type', 'today'],
            ['start_date', date('Y-m-d')],
            ['end_date', date('Y-m-d')],
            ['page', 1],
            ['limit', 20],
        ]);
        /** @var MerchantStoreMetricServices $services */
        $services = app()->make(MerchantStoreMetricServices::class);
        return app('json')->success($services->consumeDetail($access, $filter));
    }

    /**
     * 商家预约列表：active_store_id ∈ scope；普通员工仅本人服务单
     */
    public function reservationList(Request $request)
    {
        [$uid, $access] = $this->access($request);
        $filter = $request->getMore([
            ['status', ''],
            ['search', ''],
            ['date', ''],
            ['start_date', ''],
            ['end_date', ''],
            ['oid', 0],
            ['page', 1],
            ['limit', 20],
        ]);
        /** @var MerchantReservationServices $services */
        $services = app()->make(MerchantReservationServices::class);
        return app('json')->success($services->listOrders($uid, $access, $filter));
    }

    /**
     * 商家预约状态统计：与 list 同范围
     */
    public function reservationStatistics(Request $request)
    {
        [$uid, $access] = $this->access($request);
        $filter = $request->getMore([
            ['date', ''],
            ['start_date', ''],
            ['end_date', ''],
        ]);
        /** @var MerchantReservationServices $services */
        $services = app()->make(MerchantReservationServices::class);
        return app('json')->success($services->statistics($uid, $access, $filter));
    }

    /**
     * 商家预约详情：校验 scope + 当前店 + 普通员工本人
     */
    public function reservationDetail(Request $request, $id = 0)
    {
        [$uid, $access] = $this->access($request);
        $rid = (int)($id ?: $request->param('id', 0));
        /** @var MerchantReservationServices $services */
        $services = app()->make(MerchantReservationServices::class);
        return app('json')->success($services->detail($uid, $access, $rid));
    }

    /** 商家预约房间列表（接单选房） */
    public function reservationTables(Request $request)
    {
        [$uid, $access] = $this->access($request);
        /** @var MerchantReservationServices $services */
        $services = app()->make(MerchantReservationServices::class);
        return app('json')->success($services->tableList($uid, $access));
    }

    /** 商家预约接单确认 */
    public function reservationConfirm(Request $request, $id = 0)
    {
        [$uid, $access] = $this->access($request);
        $rid = (int)($id ?: $request->param('id', 0));
        [$tableId, $tableName] = $request->postMore([
            [['table_id', 'd'], 0],
            ['table_name', ''],
        ], true);
        /** @var MerchantReservationServices $services */
        $services = app()->make(MerchantReservationServices::class);
        $services->confirm($uid, $access, $rid, (int)$tableId, (string)$tableName);
        return app('json')->success('接单成功');
    }

    /** 商家预约拒绝 */
    public function reservationRefuse(Request $request, $id = 0)
    {
        [$uid, $access] = $this->access($request);
        $rid = (int)($id ?: $request->param('id', 0));
        [$refuseReason] = $request->postMore([
            ['refuse_reason', ''],
        ], true);
        /** @var MerchantReservationServices $services */
        $services = app()->make(MerchantReservationServices::class);
        $services->refuse($uid, $access, $rid, (string)$refuseReason);
        return app('json')->success('已拒绝');
    }

    /** 商家预约修改（店长；与 store update 字段对齐） */
    public function reservationUpdate(Request $request, $id = 0)
    {
        [$uid, $access] = $this->access($request);
        $rid = (int)($id ?: $request->param('id', 0));
        if ($rid <= 0) {
            return app('json')->fail('参数错误');
        }
        $data = $request->postMore([
            ['reservation_name', ''],
            ['reservation_phone', ''],
            ['reservation_time', ''],
            [['reservation_time_id', 'd'], 0],
            ['reservation_start', ''],
            ['reservation_end', ''],
            [['service_duration_minutes', 'd'], 0],
            ['service_staff_id', 0],
            ['sync_all', []],
            ['staff_choose', []],
            ['reservation_address', ''],
            ['addon_items', []],
            ['custom_form', []],
            ['mark', ''],
        ]);
        if (!empty($data['reservation_address'])) {
            $data['reservation_address'] = str_replace('/', ' ', $data['reservation_address']);
        }
        /** @var MerchantReservationServices $services */
        $services = app()->make(MerchantReservationServices::class);
        $services->update($uid, $access, $rid, $data);
        return app('json')->success('修改成功');
    }

    /** 商家预约开始/结束服务 */
    public function reservationServiceSet(Request $request, $id = 0)
    {
        [$uid, $access] = $this->access($request);
        $rid = (int)($id ?: $request->param('id', 0));
        [$status, $serviceDescribe, $serviceImages] = $request->postMore([
            ['status', 1],
            ['service_describe', ''],
            ['service_images', ''],
        ], true);
        /** @var MerchantReservationServices $services */
        $services = app()->make(MerchantReservationServices::class);
        $result = $services->setServiceStatus($uid, $access, $rid, (int)$status, [
            'service_describe' => $serviceDescribe,
            'service_images' => $serviceImages,
        ]);
        return app('json')->success('操作成功', $result);
    }

    public function debtList(Request $request)
    {
        [$uid, $access, $accessServices] = $this->access($request);
        // 独立欠款权限；普通员工默认不授予，禁止绕过首页 developing
        $accessServices->requirePermissions($access, ['merchant.debt.view'], '暂无欠款查看权限');
        $storeId = (int)($access['active_store_id'] ?? 0);
        $scopeIds = array_map('intval', $access['scope_store_ids'] ?? []);
        if ($storeId <= 0 || !in_array($storeId, $scopeIds, true)) {
            return app('json')->fail('请先选择有效门店');
        }
        [$page, $limit] = $request->getMore([
            ['page', 1],
            ['limit', 20],
        ], true);
        $where = [
            'store_id' => $storeId,
            'status' => StoreDebt::STATUS_PENDING,
            'keyword' => $request->param('keyword', ''),
        ];
        /** @var StoreDebtServices $services */
        $services = app()->make(StoreDebtServices::class);
        return app('json')->success($services->getAdminList($where, (int)$page, (int)$limit));
    }
}
