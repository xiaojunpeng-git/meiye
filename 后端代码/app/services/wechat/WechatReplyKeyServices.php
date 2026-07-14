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
declare (strict_types=1);

namespace app\services\wechat;

use app\services\BaseServices;
use app\dao\wechat\WechatReplyKeyDao;

/**
 *
 * Class UserWechatuserServices
 * @package app\services\user
 * @mixin WechatReplyKeyDao
 */
class WechatReplyKeyServices extends BaseServices
{

    /**
     * 构造方法
     * WechatReplyKeyServices constructor.
     * @param WechatReplyKeyDao $dao
     */
    public function __construct(WechatReplyKeyDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * @param array $where
     * @return mixed
     */
    public function getReplyKeyAll(array $where)
    {
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getReplyKeyList($where, $page, $limit);
        $count = $this->dao->count($where);
        return compact('list', 'count');
    }
}
