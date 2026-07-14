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
namespace app\controller\api\v2\spread;


use app\Request;
use app\services\spread\AgentLevelServices;
use app\services\spread\AgentLevelTaskServices;

/**
 * 分销等级控制器
 * Class AgentLevel
 * @package app\controller\api\v2\agent
 */
class AgentLevel
{
    /**
     * @var AgentLevelServices
     */
    protected $services;

    /**
     * AgentLevel constructor.
     * @param AgentLevelServices $services
     */
    public function __construct(AgentLevelServices $services)
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
        return app('json')->successful($this->services->detection((int)$request->uid()));
    }

    /**
     * 分销员等级列表
     * @param Request $request
     * @return mixed
     */
    public function levelList(Request $request)
    {
        return app('json')->successful($this->services->getUserlevelList((int)$request->uid()));
    }

    /**
     * 获取等级任务
     * @param Request $request
     * @param AgentLevelTaskServices $services
     * @param $id
     * @return mixed
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function levelTaskList(Request $request, AgentLevelTaskServices $services, $id)
    {
        return app('json')->successful($services->getUserLevelTaskList((int)$request->uid(), (int)$id));
    }

    /**
     * 会员详情
     * @param Request $request
     * @return mixed
     */
    public function userLevelInfo(Request $request)
    {
        return app('json')->successful($this->services->getUserLevelInfo((int)$request->uid()));
    }

}
