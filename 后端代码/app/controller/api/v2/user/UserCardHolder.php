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
namespace app\controller\api\v2\user;

use app\Request;
use app\services\store\SystemStoreStaffServices;
use app\services\user\UserCardHolderServices;


/**
 * 卡包类
 * Class UserCardHolder
 * @package app\controller\api\v2\user
 */
class UserCardHolder
{
    /**
     * @var UserCardHolderServices
     */
    protected $services;

    /**
     * UserCardHolder constructor.
     * @param UserCardHolderServices $services
     */
    public function __construct(UserCardHolderServices $services)
    {
        $this->services = $services;
    }

    /**
     * 获取卡包列表
     * @param Request $request
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getCardHolder(Request $request)
    {
        $loginUid = (int)$request->uid();
        $getUid = (int)$request->get('uid', 0);
        $uid = $getUid ?: $loginUid;
        if ($getUid && $getUid !== $loginUid) {
            /** @var SystemStoreStaffServices $staffServices */
            $staffServices = app()->make(SystemStoreStaffServices::class);
            try {
                $staffInfo = $staffServices->getStaffInfoByUid($loginUid)->toArray();
            } catch (\Throwable $e) {
                $staffInfo = [];
            }
            $staffServices->assertViewCustomerCardPermission($staffInfo);
        }
        return app('json')->successful($this->services->getCardHolderList($uid));
    }

    /**
     * 卡项信息
     * @param Request $request
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function cardHolder(Request $request, $id)
    {
        if (!$id) {
            app('json')->fail('缺少参数');
        }
        $cardHolder = $this->services->oneCardHolder((int)$id);
        if ($cardHolder) {
            return app('json')->successful($cardHolder);
        } else {
            return app('json')->fail('数据有误');
        }
    }

    /**
     * 删除卡包
     * @param Request $request
     * @param $id
     * @return \think\Response
     */
    public function delCardHolder(Request $request, $id)
    {
        if (!$id) {
            app('json')->fail('缺少参数');
        }
        $res = $this->services->userDelCardHolder((int)$id);
        if ($res) {
            return app('json')->successful('删除成功');
        } else {
            return app('json')->fail('删除失败');
        }
    }
}
