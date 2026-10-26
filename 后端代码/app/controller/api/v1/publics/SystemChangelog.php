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

namespace app\controller\api\v1\publics;

use app\services\system\SystemChangelogServices;

/**
 * 系统更新日志（小程序公开）
 * Class SystemChangelog
 * @package app\controller\api\v1\publics
 */
class SystemChangelog
{
    /**
     * @var SystemChangelogServices
     */
    protected $services;

    /**
     * SystemChangelog constructor.
     * @param SystemChangelogServices $services
     */
    public function __construct(SystemChangelogServices $services)
    {
        $this->services = $services;
    }

    /**
     * 已发布日志列表
     * @return \think\Response
     */
    public function lst()
    {
        return app('json')->successful($this->services->getPublicList('mini'));
    }

    /**
     * 日志详情
     * @param int $id
     * @return \think\Response
     */
    public function detail($id)
    {
        return app('json')->successful($this->services->getPublicDetail((int)$id, 'mini'));
    }

    /**
     * 未读信息
     * @return \think\Response
     */
    public function unread()
    {
        return app('json')->successful($this->services->getUnreadInfo('mini'));
    }
}
