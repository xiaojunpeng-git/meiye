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
namespace app\controller\api\v1\activity;


use app\Request;
use app\services\activity\integral\StoreIntegralCategoryServices;
use app\services\activity\integral\StoreIntegralServices;
use think\db\exception\DataNotFoundException;
use think\db\exception\DbException;
use think\db\exception\ModelNotFoundException;


/**
 * 积分商城
 * Class StoreIntegral
 * @package app\controller\api\activity
 */
class StoreIntegral
{

    protected $services;

    public function __construct(StoreIntegralServices $services)
    {
        $this->services = $services;
    }

    /**
     * 积分配置
     * @return \think\Response
     */
    public function getConfig()
    {
        $data = [
            'integral_effective_status' => (int)sys_config('integral_effective_status', 1),//积分有效期开启
        ];
        return app('json')->success($data);
    }
	/**
	 * 积分商城首页数据
	 * @param Request $request
	 * @return \think\Response
	 * @throws DataNotFoundException
	 * @throws DbException
	 * @throws ModelNotFoundException
	 */
    public function index(Request $request)
    {
		$data['banner'] = sys_data('integral_shop_banner') ?? [];// 积分商城banner
		$where = ['is_show' => 1];
		$where['is_host'] = 1;
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$list = $this->services->getIntegralList($where, $uid);
		$data['list'] = get_thumb_water($list, 'mid');
		$userInfo = $request->hasMacro('user')  ? $request->user()->toArray() : [];
		$data['integral'] = $userInfo['integral'] ?? 0;
		return app('json')->successful($data);
    }

    /**
     * 商品列表
     * @param Request $request
     * @return mixed
     */
    public function lst(Request $request)
    {
		$where = $request->getMore([
			['store_name', ''],
			['priceOrder', ''],
			['salesOrder', ''],
			['range', ''],
		]);
		$where['is_show'] = 1;
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
		$list = $this->services->getIntegralList($where, $uid);
        return app('json')->successful(get_thumb_water($list, 'mid'));
    }

    /**
     * 积分商品详情
     * @param Request $request
     * @param $id
     * @return mixed
     * @throws DataNotFoundException
     * @throws DbException
     * @throws ModelNotFoundException
     */
    public function detail(Request $request, $id)
    {
		if (!$id || !is_numeric($id)) {
			return app('json')->fail('参数错误');
		}
        $data = $this->services->integralDetail((int)$request->uid(), $id);
        return app('json')->successful($data);
    }


	/**
	 * @param StoreIntegralCategoryServices $services
	 * @return \think\Response
	 */
	public function category(StoreIntegralCategoryServices $services)
	{
		return app('json')->successful($services->getTreeList());
	}

}
