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
use app\services\community\CommunityRecordServices;
use app\services\message\service\StoreServiceRecordServices;
use app\services\message\SystemMessageServices;


/**
 * 系统消息控制器
 * Class User
 * @package app\controller\api\v1\user
 */
class SystemMessage
{
    /**
     * @var SystemMessageServices
     */
    protected $services;

    /**
     * SystemMessage constructor.
     * @param SystemMessageServices $services
     */
    public function __construct(SystemMessageServices $services)
    {
        $this->services = $services;
    }

	/**
	 * 获取用户消息 站内信+客服消息
	 * @param Request $request
	 * @param StoreServiceRecordServices $services
	 * @return \think\Response
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
	public function message(Request $request,StoreServiceRecordServices $services, CommunityRecordServices $RecordServices)
	{
		$uid = (int)$request->uid();
		$where['is_del'] = 0;
		$where['uid'] = $uid;
		$list = $this->services->getMessageList($where, '*', 0, 1);
		$list = $list[0] ?? [];
		if ($list) {
			$list['add_time'] = time_tran($list['add_time']);
			$list['message_num'] = $this->services->count(['uid' => $uid, 'look' => 0, 'is_del' => 0]);
		}
        $service = $services->getServiceList($uid);
        $communityRecord = $RecordServices->getList(['uid' => $uid], '*', 0, 1);
        $communityRecord = $communityRecord[0] ?? [];
        return app('json')->successful(['system' => $list, 'service' => $service, 'community' => $communityRecord]);
	}

    /**
     * 站内信列表
     * @param Request $request
     * @return mixed
     */
    public function message_list(Request $request)
    {
        $uid = (int)$request->uid();
        return app('json')->successful($this->services->getSystemMessageList($uid));
    }

    /**
     * 站内信详情
     * @param Request $request
     * @param $id
     * @return mixed
     */
    public function detail(Request $request, $id)
    {
        if (!$id || !is_numeric($id)) {
            return app('json')->fail('消息ID参数错误');
        }
        $uid = (int)$request->uid();
        return app('json')->successful($this->services->getInfo((int)$id, $uid));
    }
}
