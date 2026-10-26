<?php
/**
 *  +----------------------------------------------------------------------
 *  | MOHE [ MOHE赋能开发者，助力企业发展 ]
 *  +----------------------------------------------------------------------
 *  | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
 *  +----------------------------------------------------------------------
 *  | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
 *  +----------------------------------------------------------------------
 *  | Author: MOHE Team <admin@mohe.com>
 *  +----------------------------------------------------------------------
 */

namespace app\http\middleware;


use app\Request;
use mohe\exceptions\ApiException;
use mohe\interfaces\MiddlewareInterface;
use mohe\services\CacheService;

/**
 * reids锁
 * Class BlockerMiddleware
 * @author 等风来
 * @email 136327134@qq.com
 * @date 2022/11/21
 * @package app\http\middleware\api
 */
class BlockerMiddleware implements MiddlewareInterface
{

	/**
	 * @param Request $request
	 * @param \Closure $next
	 * @param string $type
	 * @return mixed
	 */
    public function handle(Request $request, \Closure $next, string $type = 'user')
    {
		$id = 0;
		switch ($type){
			case 'user'://移动端
				$id = $request->uid();
				break;
			case 'cashier'://收银台
				$id = $request->cashierId();
				break;
		}
        $key = md5($request->rule()->getRule() . $id. json_encode($request->param()));
        if (!CacheService::setMutex($key)) {
            throw new ApiException('操作太频繁，请稍后再试');
        }

        $response = $next($request);

        $this->after($response, $key);

        return $response;
    }

	/**
	 * @param $response
	 * @param $key
	 * @return void
	 */
    public function after($response, $key)
    {
        CacheService::delMutex($key);
    }
}
