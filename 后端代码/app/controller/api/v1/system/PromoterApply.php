<?php

namespace app\controller\api\v1\system;

use app\Request;
use app\services\other\AgreementServices;
use app\services\system\SystemUserApplyServices;
use app\services\user\UserServices;
use mohe\services\CacheService;
use think\facade\App;
use function app;

/**
 * 分销员申请类
 * Class SupplierApply
 * @package app\controller\api\v1\system
 */
class PromoterApply
{
	/**
	 * @var SystemUserApplyServices
	 */
	protected $services;

	/**
	 * StoreService constructor.
	 * @param SystemUserApplyServices $services
	 */
	public function __construct(App $app, SystemUserApplyServices $services)
	{
		$this->services = $services;
	}

	/**
	 * 申请详情
	 * @param Request $request
	 * @param AgreementServices $agreementServices
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function applyInfo(Request $request, AgreementServices $agreementServices)
    {
		$uid = (int)$request->uid();
		$user = $request->user();
		$applyInfo = $this->services->get(['uid' => $uid, 'is_del' => 0, 'type' => 0]);
		$user = [
			'id' => $applyInfo['id'] ?? 0,
			'uid' => $uid,
			'nickname' => $applyInfo['name'] ?? $user['nickname'] ?? '',
			'real_name' => $applyInfo['system_name'] ?? $user['real_name'] ?? '',
			'phone' => $user['phone'] ?? '',
			'status' => $applyInfo ? $applyInfo['status'] : -1,
			'add_time' => $applyInfo ? date('Y/m/d H:i', $applyInfo['add_time']) : '',
			'status_time' => $applyInfo && $applyInfo['status_time'] ? date('Y/m/d H:i', $applyInfo['status_time']) : '',
		];
		$agreement = $agreementServices->getAgreementBytype(2);
        return app('json')->success(compact('user', 'agreement'));
    }

	/**
	 * 申请分销员
	 * @param Request $request
	 * @param $id
	 * @return \think\Response
	 */
    public function applyPromoter(Request $request, $id)
    {
        $data = $request->postMore([
            ['nickname', '', '', 'name'],
            ['real_name', '', '', 'system_name'],
            ['phone', ''],
            ['code', 0]
        ]);
		$uid = (int)$request->uid();
        $userInfo = $request->user();
        $verifyCode = CacheService::get('code_' . $data['phone']);
        if ($verifyCode != $data['code']) return app('json')->fail('验证码错误');
        unset($data['code']);
		if (!sys_config('brokerage_func_status')) return app('json')->fail('未开启推广功能');
		if (sys_config('store_brokerage_statu') != 1) return app('json')->fail('非指定分销模式无需申请推广员');
		if ($userInfo['is_promoter']) return app('json')->fail('您已经是推广员');
		if ($data['phone'] != $userInfo['phone']) {
			$phoneUsed = app()->make(UserServices::class)->count(['phone' => $data['phone']]);
			if ($phoneUsed) return app('json')->fail('该手机号已被使用');
		}
        $id = $this->services->saveApply((int)$id, $uid, $data, 0);
        return app('json')->success('申请成功', ['id' => $id]);
    }
}
