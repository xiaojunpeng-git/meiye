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

namespace app\controller\store\order;

use app\common\controller\Recharge as CommonRecharge;
use app\controller\store\AuthController;
use app\services\user\UserRechargeServices;
use think\facade\App;

/**
 * 储值
 * Class Recharge
 * @package app\controller\store\order
 */
class Recharge extends AuthController
{

    use CommonRecharge;

    /**
     * @var UserRechargeServices
     */
    protected $services;

    /**
     * Order constructor.
     * @param App $app
     * @param UserRechargeServices $services
     */
    public function __construct(App $app, UserRechargeServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * @return mixed
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['data', ''],
            ['paid', 1],
            ['staff_id', ''],
        ]);
        $where['nickname'] = $this->request->param('nickname', '');
        $where['store_id'] = $this->storeId;
        return $this->success($this->services->getRechargeList($where, '*', 0, ['staff']));
    }

	/**
	 * 获取用户储值数据
	 * @return array
	 */
	public function user_recharge()
	{
		$where = $this->request->getMore([
			['data', ''],
			['paid', ''],
			['nickname', ''],
		]);
		$where['store_id'] = $this->storeId;
		return $this->success($this->services->user_recharge($where));
	}

    /**
     * 获取备注
     * @param $id
     * @return mixed
     */
    public function getRemark($id)
    {
        if (!$id || !is_numeric($id)) {
            return $this->fail('参数错误');
        }
        return $this->success(['remarks' => $this->services->value(['id' => $id], 'remarks')]);
    }

    /**
     * @param $id
     * @return mixed
     */
    public function remarks($id)
    {
        if (!$id || !is_numeric($id)) {
            return $this->fail('参数错误');
        }
        $data = $this->request->param('remarks', '');

        $this->services->update(['id' => $id], ['remarks' => $data]);

        return $this->success('备注提交成功');
    }

}
