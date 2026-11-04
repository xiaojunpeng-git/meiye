<?php
// 二维码打开环境由实例配置决定，不能由客户端参数切换，也不能按客户域名写分支。
// 正式站默认 release；仅体验实例显式设置 MEMBER_QRCODE_ENV_VERSION=trial。
return ['env_version' => env('MEMBER_QRCODE_ENV_VERSION', 'release')];
