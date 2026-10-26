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
namespace app\controller\admin\v1\wechat;



use app\controller\admin\AuthController;

/**
 * 用户扫码点击事件
 * Class SystemMessage
 * @package app\controller\admin\v1\application\wechat
 */
class WechatMessage extends AuthController
{
    /**
     * 显示操作记录
     */
    public function index()
    {
        $where = $this->getMore([
            ['page', 1],
            ['limit', 20],
            ['nickname', ''],
            ['type', ''],
            ['data', ''],
        ]);
        return $this->success([]);
    }

    /**
     * 操作名称列表
     * @return mixed
     */
    public function operate()
    {

        return $this->success([]);
    }

}

