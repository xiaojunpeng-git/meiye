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
return [
    //默认上传模式
    'default' => 'local',
    //上传文件大小
    'filesize' => 50 * 1024 * 1024,
    //上传文件后缀类型
    'fileExt' => ['jpg', 'jpeg', 'png', 'gif', 'ico', 'pem', 'mp3', 'wma', 'wav', 'amr', 'mp4', 'key', 'xlsx', 'xls', 'video/mp4'],
    //上传文件类型
    'fileMime' => [
		'image/jpg',
        'image/jpeg',
        'image/gif',
        'image/png',
        'text/plain',
        'audio/mpeg',
		'video/mp4',
        'application/octet-stream',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/vnd.ms-works',
        'application/vnd.ms-excel',
        'application/zip',
        'application/vnd.ms-excel',
        'application/vnd.ms-excel',
        'text/xml',
		'image/x-icon',
		'image/vnd.microsoft.icon',
    ],
    //驱动模式
    'stores' => [
        //本地上传配置
        'local' => [],
		//七牛云上传配置
		'qiniu' => [
		],
		//oss 阿里云上传配置
		'oss' => [
		],
		//cos 腾讯云上传配置
		'cos' => [
		],
		//oss 京东云
		'jdoss' => [
		],
		//oss 华为云
		'obs' => [
		],
		//oss 天翼云
		'tyoss' => [
		],
    ]
];
