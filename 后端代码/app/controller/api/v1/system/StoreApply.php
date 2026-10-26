<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
namespace app\controller\api\v1\system;

use app\Request;
use app\services\other\CityAreaServices;
use app\services\store\SystemStoreServices;
use app\services\store\SystemStoreStaffServices;
use app\services\system\SystemUserApplyServices;
use app\validate\api\user\UserApplyValidate;
use mohe\services\CacheService;


/**
 * 加盟店申请类
 * Class StoreApply
 * @package app\controller\api\v1\system
 */
class StoreApply
{
	/**
     * @var SystemUserApplyServices
     */
    protected $services;

    /**
     * SupplierApply constructor.
     * @param SystemUserApplyServices $services
     */
    public function __construct(SystemUserApplyServices $services)
    {
        $this->services = $services;
    }

	/**
	 * 获取单个申请详情
	 * @param Request $request
	 * @param SystemStoreServices $storeServices
	 * @param SystemStoreStaffServices $storeStaffServices
	 * @param $id
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function getInfo(Request $request, SystemStoreServices $storeServices, SystemStoreStaffServices $storeStaffServices, CityAreaServices $services, $id)
	{
		if (!$id || !is_numeric($id)) {
            return app('json')->fail('参数错误');
        }
		$info = $this->services->get((int)$id);
		if (!$info || $info['uid'] != $request->uid()) {
			return app('json')->fail('数据不存在');
		}
		$info = $info->toArray();
		$data = ['url' => sys_config('site_url') . '/'. config('admin.store_prefix'), 'account' => '', 'pwd' => ''];
		if ($info['status'] == 1 && $info['relation_id']) {//审核通过
			$data['account'] = $info['phone'];
			$storeInfo = $storeServices->get(['id' => $info['relation_id']], ['id']);
			if ($storeInfo) {
				$adminInfo = $storeStaffServices->get(['store_id' => $storeInfo['id'], 'level' => 0, 'is_admin' => 1, 'is_manager' => 1], ['id', 'account']);
				if ($adminInfo) $data['account'] = $adminInfo['account'] ?? '';
			}
			$data['pwd'] = substr($info['phone'], -6);
		}
		$info['add_time'] = $info['add_time'] ? date('Y-m-d H:i', $info['add_time']) : '';
		$info['status_time'] = $info['status_time'] ? date('Y-m-d H:i', $info['status_time']) : '';
		$info = array_merge($info, $data);
		$info['city_list'] = [];
		$address = implode('/', [$info['province'] ?? '', $info['city'] ?? '', $info['district'] ?? '', $info['street'] ?? '']);
		$city = $services->searchCity(compact('address'));
		if ($city) {
			$where = [['id', 'in', array_merge([$city['id']], explode('/', trim($city->path, '/')))]];
			$info['city_list'] = $services->getCityList($where, 'id as value,id,name as label,parent_id as pid');
		}
		return app('json')->success($info);
	}


	/**
	 * 申请加盟店
	 * @param Request $request
	 * @param $id
	 * @return \think\Response
	 */
    public function userApply(Request $request, $id)
    {
        $data = $request->postMore([
			['name', ''],//用户名称
            ['phone', ''],//手机号
            ['system_name', ''],//加盟店名称
//			['captcha', ''],//验证码
			['address', []],//地址
			['detail', ''],//详细地址
            ['images', []],//资质图片
			['latitude', ''],
			['longitude', ''],
        ]);
		//验证手机号
		validate(UserApplyValidate::class)->check($data);

//		$captcha = $data['captcha'];
//		unset($data['captcha']);
//		//验证验证码
//		try {
//			check_sms_code($data['phone'], $captcha);
//		} catch (\Throwable $e) {
//			return app('json')->fail($e->getMessage());
//		}
		if (!isset($data['address']['province']) || !$data['address']['province'] || $data['address']['province'] == '省') return app('json')->fail('地址格式错误!');
		if (!isset($data['address']['city']) || !$data['address']['city'] || $data['address']['city'] == '市') return app('json')->fail('地址格式错误!');
		if (!isset($data['address']['district']) || !$data['address']['district'] || $data['address']['district'] == '区') return app('json')->fail('地址格式错误!');
		if (!isset($data['address']['city_id']) && $data['type'] == 0) {
			return app('json')->fail('地址格式错误!请重新选择!');
		}
		$data['province'] = $data['address']['province'] ?? '';
		$data['city'] = $data['address']['city'] ?? '';
		$data['city_id'] = $data['address']['city_id'] ?? 0;
		$data['district'] = $data['address']['district'] ?? '';
		$data['street'] = $data['address']['street'] ?? '';
		unset($data['address']);

		$res = $this->services->saveApply((int)$id, (int)$request->uid(), $data, 1);
		if ($res) {
			CacheService::delete('code_' . $data['phone']);
			CacheService::delete('code_error_' . $data['phone']);
			return app('json')->successful('申请成功!', ['id' => $res]);
		}
		return app('json')->fail('申请失败!');
    }

	/**
	 * 获取申请记录
	 * @param Request $request
	 * @return \think\Response
	 */
	public function userApplyRecord(Request $request)
	{
		return app('json')->success($this->services->getUserApply((int)$request->uid(), 1));
	}

}
