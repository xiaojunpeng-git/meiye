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

namespace app\controller\store\system;

use app\controller\store\AuthController;
use app\services\system\SystemChangelogServices;
use think\facade\App;

/**
 * 系统更新日志（门店只读）
 * Class SystemChangelog
 * @package app\controller\store\system
 */
class SystemChangelog extends AuthController
{
    /**
     * SystemChangelog constructor.
     * @param App $app
     * @param SystemChangelogServices $services
     */
    public function __construct(App $app, SystemChangelogServices $services)
    {
        parent::__construct($app);
        $this->services = $services;
    }

    /**
     * 已发布日志列表
     * @return \think\Response
     */
    public function index()
    {
        [$page, $limit] = $this->request->getMore([
            ['page', 0],
            ['limit', 0],
        ], true);
        return $this->success($this->services->getPublicList('store', (int)$page, (int)$limit));
    }

    /**
     * 日志详情
     * @param int $id
     * @return \think\Response
     */
    public function read($id)
    {
        return $this->success($this->services->getPublicDetail((int)$id, 'store'));
    }
}
