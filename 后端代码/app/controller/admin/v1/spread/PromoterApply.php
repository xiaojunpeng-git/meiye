<?php

namespace app\controller\admin\v1\spread;

use app\controller\admin\AuthController;
use app\services\system\SystemUserApplyServices;
use app\services\user\UserServices;
use think\facade\App;

class PromoterApply extends AuthController
{
	/**
	 * 分销员申请类型
	 * @var int
	 */
	protected $type = 0;

	/**
	 * @var SystemUserApplyServices
	 */
	protected $services;

	/**
	 * 构造方法
	 * @param App $app
	 * @param SystemUserApplyServices $services
	 */
	public function __construct(App $app, SystemUserApplyServices $services)
	{
		parent::__construct($app);
		$this->services = $services;
	}

    /**
     * 分销员申请列表
     * @return \think\Response
     * @author wuhaotian
     * @email 442384644@qq.com
     * @date 2024/8/21
     */
    public function applyList()
    {
        $where = $this->request->getMore([
			['data', '', '', 'time'],
            ['status', ''],
            ['keyword', ''],
        ]);
		$where['is_del'] = 0;
		$where['type'] = $this->type;
        return $this->success($this->services->getApplyList($where));
    }



	/**
	 * 申请审核
	 * @param UserServices $userServices
	 * @param $id
	 * @param $uid
	 * @param $status
	 * @return mixed
	 */
    public function applyVerify(UserServices $userServices, $id, $uid, $status)
    {
		$this->services->update(['id' => $id], ['status' => $status, 'status_time' => time()]);
		if ($status == 1) {
			$userServices->update(['uid' => $uid], ['is_promoter' => 1]);
		}
        return $this->success($status == 1 ? '审核通过' : '拒绝成功');
    }

	/**
	 * 申请删除
	 * @param $id
	 * @return mixed
	 */
    public function applyDel($id)
    {
		$this->services->update(['id' => $id], ['is_del' => 1]);
        return $this->success('删除成功');
    }
}