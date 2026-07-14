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
use app\services\message\service\StoreServiceLogServices;
use app\services\message\service\StoreServiceServices;

/**
 * 客服聊天
 * Class StoreService
 * @package app\controller\api\v2\user
 */
class StoreService
{

    /**
     * @var StoreServiceLogServices
     */
    protected $services;

    /**
     * StoreService constructor.
     * @param StoreServiceLogServices $services
     */
    public function __construct(StoreServiceLogServices $services)
    {
        $this->services = $services;
    }

    /**
     * 客服聊天记录
     * @param Request $request
     * @param $toUid
     * @return array
     */
    public function record(Request $request, StoreServiceServices $services)
    {
        [$uidTo, $limit, $toUid] = $request->getMore([
            [['uidTo', 'd'], 0],
            [['limit', 'd'], 10],
            [['toUid', 'd'], 0],
        ], true);
        $uid = (int)$request->uid();
        return app('json')->successful($services->getRecord($uid, $uidTo, $limit, $toUid));
    }
}
