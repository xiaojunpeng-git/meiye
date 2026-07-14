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

use app\ExceptionHandle;
use app\Request;
use app\Route;

// 容器Provider定义文件
return [
    'think\Request'          => Request::class,
    'think\Route'            => Route::class,
    'think\exception\Handle' => ExceptionHandle::class,
];
