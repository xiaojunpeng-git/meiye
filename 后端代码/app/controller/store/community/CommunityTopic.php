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
namespace app\controller\store\community;

use app\controller\store\AuthController;
use app\Request;
use app\services\community\CommunityTopicServices;
use mohe\exceptions\AdminException;
use think\facade\App;

/**
 * 社区话题
 * Class CommunityTopic
 * @package app\controller\store\community
 */
class CommunityTopic extends AuthController
{

    /**
     * @var CommunityTopicServices
     */
    public function __construct(App $app, CommunityTopicServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
	 * 获取所有话题列表
	 * @return mixed
	 * @throws \think\db\exception\DataNotFoundException
	 * @throws \think\db\exception\DbException
	 * @throws \think\db\exception\ModelNotFoundException
	 */
    public function allTopic()
    {
        $where['is_del'] = 0;
        return $this->success($this->services->getAllTopic($where,true));
    }

}
