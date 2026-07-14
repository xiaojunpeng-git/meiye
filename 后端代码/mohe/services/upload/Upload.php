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
namespace mohe\services\upload;

use mohe\basic\BaseManager;
use think\facade\Config;

/**
 * Class Upload
 * @package mohe\services\upload
 * @mixin \mohe\services\upload\storage\Local
 * @mixin \mohe\services\upload\storage\OSS
 * @mixin \mohe\services\upload\storage\COS
 * @mixin \mohe\services\upload\storage\Qiniu
 * @mixin \mohe\services\upload\storage\Jdoss
 * @mixin \mohe\services\upload\storage\Obs
 * @mixin \mohe\services\upload\storage\Tyoss
 */
class Upload extends BaseManager
{
    /**
     * 空间名
     * @var string
     */
    protected $namespace = '\\mohe\\services\\upload\\storage\\';

    /**
     * 设置默认上传类型
     * @return mixed
     */
    protected function getDefaultDriver()
    {
        return Config::get('upload.default', 'local');
    }


}
