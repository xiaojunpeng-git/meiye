<?php
namespace app\controller\api\v1\merchant;

use app\Request;
use app\services\merchant\MerchantAccessServices;
use app\services\report\BusinessLedgerServices;

/** 商家端工程管理台账：范围固定为当前身份的有效门店集合。 */
class EngineeringLedger
{
    private function context(Request $request): array
    {
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : (int)($request->storeStaffInfo['uid'] ?? $request->storeStaffInfo['employee_id'] ?? 0);
        $access = app()->make(MerchantAccessServices::class)->resolveAccess($uid, [
            'active_store_id' => (int)($request->storeId ?? $request->param('active_store_id', 0)),
            'active_role' => (string)$request->param('active_role', ''),
        ]);
        if (empty($access['can_enter_merchant'])) throw new \think\exception\ValidateException('当前账号暂无商家权限');
        $stores = array_values(array_unique(array_filter(array_map('intval', $access['scope_store_ids'] ?? []))));
        $active = (int)($access['active_store_id'] ?? 0); if ($active > 0 && in_array($active, $stores, true)) $stores = [$active];
        if (!$stores) throw new \think\exception\ValidateException('当前账号没有有效门店');
        app()->make(MerchantAccessServices::class)->requirePermissions($access, ['merchant.engineering_ledger.view'], '当前账号未配置工程管理权限');
        return [$uid, $access, ['store_ids' => $stores, 'active_store_id' => $active]];
    }

    public function catalog(Request $request, BusinessLedgerServices $services) { $this->context($request); return app('json')->success($services->catalog()); }
    public function list(Request $request, BusinessLedgerServices $services) { [, , $scope] = $this->context($request); $input = $request->getMore([['type',''],['page',1],['limit',20],['keyword',''],['start_date',''],['end_date','']]); try { return app('json')->success($services->list((string)$input['type'], $scope, $input)); } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); } }
    public function read(Request $request, BusinessLedgerServices $services, $id = 0) { [, , $scope] = $this->context($request); $type = (string)$request->param('type',''); try { return app('json')->success($services->read($type, (int)($id ?: $request->param('id',0)), $scope)); } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); } }
    public function save(Request $request, BusinessLedgerServices $services) { [$uid, $access, $scope] = $this->context($request); $payload = $request->post(); $payload['store_id'] = (int)($access['active_store_id'] ?? 0); try { return app('json')->success($services->save((string)($payload['type'] ?? ''), $scope, $payload, ['id' => $uid, 'name' => (string)($access['active_role'] ?? '')])); } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); } }
    public function export(Request $request, BusinessLedgerServices $services) { [, , $scope] = $this->context($request); $input = $request->getMore([['type',''],['keyword',''],['start_date',''],['end_date','']]); try { $file = $services->export((string)$input['type'], $scope, $input); return download($file['path'], $file['filename']); } catch (\InvalidArgumentException $e) { return app('json')->fail($e->getMessage()); } }
}
