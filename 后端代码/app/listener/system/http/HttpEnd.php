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

namespace app\listener\system\http;

use think\facade\Log;
use think\Request;
use think\Response;

/**
 * 订单创建事件
 * Class HttpEnd
 * @package app\listener\http
 */
class HttpEnd
{
    public function handle(Response $response):void
    {
		//业务成功和失败分开存储
		$data = $response->getData();
		//业务成功和失败分开存储
		$status = is_array($data) ? ($data["status"] ?? 0) : 0;
		if ($status == 200) {
			//业务成功日志开关
			if (!config("log.success_log")) return;
			$logType = "success";
		} else {
			//业务失败日志开关
			if (!config("log.fail_log")) return;
			$logType = "fail";
		}
		$request = app()->make(Request::class);
		$id = 0;
		$type = '';
		//当前用户身份标识
		foreach ([
					 'adminId' => 'admin',
					 'kefuId' => 'kefu',
					 'uid' => 'user',
					 'supplierId' => 'supplier',
					 'cashierId' => 'cashier',
					 'storeId' => 'store',
					 'outId' => 'out',
				 ] as $value => $vv) {
			if ($request->hasMacro($value)) {
				$id = $request->{$value}();
				$type = $vv;
			}
		}
		response_log_write((array)$response->getData(), $logType, (string)$type, (int)$id);
    }
}
