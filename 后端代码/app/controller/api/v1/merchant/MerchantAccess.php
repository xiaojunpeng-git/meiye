<?php
namespace app\controller\api\v1\merchant;

use app\Request;
use app\services\merchant\MerchantAccessServices;

class MerchantAccess
{
    /** @var MerchantAccessServices */
    protected $services;

    public function __construct(MerchantAccessServices $services)
    {
        $this->services = $services;
    }

    /**
     * 商家入口权限与当前身份上下文
     */
    public function access(Request $request)
    {
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $context = $request->getMore([
            ['active_store_id', 0],
            ['active_role', ''],
        ]);
        return app('json')->success($this->services->resolveAccess($uid, $context));
    }

    /**
     * 切换当前商家身份/门店（会话态由前端持有，本接口返回校验后的新上下文）
     */
    public function switchContext(Request $request)
    {
        $uid = $request->hasMacro('uid') ? (int)$request->uid() : 0;
        $data = $request->postMore([
            ['active_store_id', 0],
            ['active_role', ''],
        ]);
        $access = $this->services->resolveAccess($uid, $data);
        if (!$access['can_enter_merchant']) {
            return app('json')->fail('当前账号暂无商家权限');
        }
        return app('json')->success($access);
    }
}
