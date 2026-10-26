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

namespace app\services\wechat;


use app\dao\wechat\WechatMediaDao;
use app\services\BaseServices;

/**
 * Class WechatMediaServices
 * @package app\services\wechat
 * @mixin WechatMediaDao
 */
class WechatMediaServices extends BaseServices
{
    /**
     * WechatMediaServices constructor.
     * @param WechatMediaDao $dao
     */
    public function __construct(WechatMediaDao $dao)
    {
        $this->dao = $dao;
    }

}
