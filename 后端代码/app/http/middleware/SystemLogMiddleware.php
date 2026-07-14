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

namespace app\http\middleware;


use app\Request;
use app\jobs\system\AdminLogJob;
use mohe\interfaces\MiddlewareInterface;

/**
 * 日志中間件
 * Class AdminLogMiddleware
 * @package app\http\middleware\admin
 */
class SystemLogMiddleware implements MiddlewareInterface
{
    /**
     * @param Request $request
     * @param \Closure $next
     * @return mixed
     */
    public function handle(Request $request, \Closure $next, string $type = 'admin')
    {
		$method = trim(strtolower($request->method()));
        $rule = trim(strtolower($request->rule()->getRule()));
		$id = 0;
		$account = '未知/未登录';
		switch ($type){
			case 'user'://移动端
				$id = $request->hasMacro('uid') ? (int)$request->uid() : 0;
				$account = $request->user()['nickname'] ?? '未知/未登录';
				break;
			case 'admin'://平台
				$id = $request->hasMacro('adminId') ? (int)$request->adminId() : 0;
				$account = $request->adminInfo()['account'] ?? '';
				break;
			case 'store'://门店
				$id = $request->hasMacro('storeStaffId') ? (int)$request->storeStaffId() : 0;
				$account = $request->storeStaffInfo()['account'] ?? '未知/未登录';
				break;
			case 'cashier'://收银台
				$id = $request->hasMacro('cashierId') ? (int)$request->cashierId() : 0;
				$account = $request->cashierInfo()['account'] ?? '未知/未登录';
				break;
			case 'supplier'://供应商
				$id = $request->hasMacro('supplierId') ? (int)$request->supplierId() : 0;
				$account = $request->supplierInfo()['supplier_name'] ?? '未知/未登录';
				break;
		}
        //记录后台日志
        AdminLogJob::dispatch([$id, $account, $method, $rule, $request->ip(), $type]);

        return $next($request);
    }

}
