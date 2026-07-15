<?php
namespace app\controller\api\v1\merchant;

use app\Request;
use app\services\merchant\MerchantAccessServices;
use app\services\merchant\MerchantCustomerServices;
use app\services\merchant\MerchantDataServices;
use app\services\merchant\MerchantHomeServices;
use app\services\merchant\MerchantYejiServices;
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
            ['field_key', ''],
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
        $accessServices->requireAnyPermission(
            $access,
            ['merchant.data.store', 'merchant.data.region', 'merchant.data.self'],
            '暂无员工统计查看权限'
        );
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
