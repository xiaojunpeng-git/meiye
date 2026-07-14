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
namespace app\controller\admin\v1\system;

use app\controller\admin\AuthController;
use app\services\system\SystemSignRewardServices;
use think\facade\App;

/**
 * 签到奖励
 * Class SystemSignReward
 * @package app\controller\admin\v1\system
 */
class SystemSignReward extends AuthController
{

    /**
     * SystemRole constructor.
     * @param App $app
     * @param SystemSignRewardServices $services
     */
    public function __construct(App $app, SystemSignRewardServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 签到奖励列表
     * @return \think\Response
     */
    public function index()
    {
        [$type] = $this->request->getMore([
            ['type', 0]
        ], true);
        $data = $this->services->getList($type);
        return app('json')->success($data);
    }

    /**
     * 新增签到奖励
     * @return \think\Response
     * @throws \FormBuilder\Exception\FormBuilderException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function addRewards()
    {
        [$type] = $this->request->getMore([
            ['type', 0]
        ], true);
        $data = $this->services->rewardsForm(0, $type);
        return app('json')->success($data);
    }

    /**
     * 修改签到奖励
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function editRewards($id)
    {
        $data = $this->services->rewardsForm($id);
        return app('json')->success($data);
    }

    /**
     * 保存签到奖励
     * @param $id
     * @return \think\Response
     * @throws \think\db\exception\DbException
     */
    public function saveRewards($id)
    {
        $data = $this->request->postMore([
            ['type', 0],
            ['days', 0],
            ['point', 0],
            ['exp', 0]
        ]);
        $this->services->saveRewards($id, $data);
        return app('json')->success('编辑成功');
    }

    /**
     * 删除签到奖励
     * @param $id
     * @return \think\Response
     */
    public function delRewards($id)
    {
        $this->services->delete($id);
        return app('json')->success('删除成功');
    }
}

