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
namespace app\controller\api\v1\user;

use app\Request;
use app\services\user\level\UserLevelServices;

/**
 * 会员等级类
 * Class UserLevel
 * @package app\controller\api\user
 */
class UserLevel
{
    /**
     * @var UserLevelServices
     */
    protected $services;

    /**
     * UserLevel constructor.
     * @param UserLevelServices $services
     */
    public function __construct(UserLevelServices $services)
    {
        $this->services = $services;
    }

    /**
     * 检测用户是否可以成为会员
     * @param Request $request
     * @return mixed
     */
    public function detection(Request $request)
    {
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        return app('json')->successful($this->services->detection($uid));
    }

    /**
     * 会员等级列表
     * @param Request $request
     * @return mixed
     */
    public function grade(Request $request)
    {
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        return app('json')->successful(['list'=>$this->services->grade($uid),'task'=>['list'=>[],'task'=>[]]]);
    }

    /**
     * 获取等级任务
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function task(Request $request, $id)
    {
        if (!$id || !is_numeric($id)) {
            return app('json')->fail('参数错误');
        }
        return app('json')->successful([]);
    }

    /**
     * 会员详情
     * @param Request $request
     * @return mixed
     */
    public function userLevelInfo(Request $request)
    {
		$uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        return app('json')->successful($this->services->getUserLevelInfo($uid));
    }

    /**
     * 经验列表
     * @param Request $request
     * @return mixed
     */
    public function expList(Request $request)
    {
        return app('json')->successful($this->services->expList((int)$request->uid()));
    }

	/**
 	* 获取会员卡激活需要的信息
	* @param Request $request
	* @return mixed
	 */
	public function activateInfo(Request $request)
	{
		return app('json')->successful($this->services->getActivateInfo());
	}

	/**
 	* 会员卡激活
	* @param Request $request
	* @return mixed
	 */
	public function activateLevel(Request $request)
	{
		$data = $request->post();
		return app('json')->successful($this->services->userActivatelevel((int)$request->uid(), $data));
	}

}
