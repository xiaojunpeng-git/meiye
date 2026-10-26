<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

// +----------------------------------------------------------------------
// | Cookie设置
// +----------------------------------------------------------------------
return [
    // cookie 保存时间
    'expire'    => 0,
    // cookie 保存路径
    'path'      => '/',
    // cookie 有效域名
    'domain'    => '',
    //  cookie 启用安全传输
    'secure'    => false,
    // httponly设置
    'httponly'  => false,
    // 是否使用 setcookie
    'setcookie' => true,
    // 跨域header
    'header'    => [
        'Access-Control-Allow-Origin'       => '*',
        // X-Request-Token：组织架构等工作台写接口幂等令牌（18081 跨域预检必须放行，否则浏览器报 Network Error）。
        // X-Mobile-*：手机端冻结合同的精确请求头，供 H5/App/小程序预检通过；认证与数据范围仍由后端逐请求校验。
        'Access-Control-Allow-Headers'      => 'Client-Userid,Authori-zation,Authorization, Content-Type, If-Match, If-Modified-Since, If-None-Match, If-Unmodified-Since, X-Requested-With, Form-type, X-Source, X-Request-Token, X-Mobile-Contract-Version, X-Mobile-Client-Session-Id, X-Mobile-Platform, X-Mobile-Request-Id, X-Mobile-App-Session, X-Mobile-Active-Context-Id, X-Mobile-State-Context-Id',
        'Access-Control-Allow-Methods'      => 'GET,POST,PATCH,PUT,DELETE,OPTIONS,DELETE',
        'Access-Control-Max-Age'            =>  '1728000',
        'Access-Control-Allow-Credentials'  => 'true',
        'Access-Control-Expose-Headers'     => 'Server'
    ],
    // token名称
    'token_name' => 'Authori-zation',
];
