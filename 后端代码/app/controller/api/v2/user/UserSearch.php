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


use app\services\user\UserSearchServices;
use think\Request;

/**
 * 用户搜索
 * Class UserSearch
 * @package app\controller\api\v2\user
 */
class UserSearch
{
    /**
     * @var UserSearchServices
     */
    protected $services;

    /**
     * UserSearch constructor.
     * @param UserSearchServices $services
     */
    public function __construct(UserSearchServices $services)
    {
        $this->services = $services;
    }

    public function getUserSeachList(Request $request)
    {
        return app('json')->successful($this->services->getUserList((int)$request->uid()));
    }

    public function cleanUserSearch(Request $request)
    {
        $uid = (int)$request->uid();
        $this->services->update(['uid' => $uid], ['is_del' => 1]);
        return app('json')->successful('删除成功');
    }
}
