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

namespace mohe\utils;

class QRcode extends \dh2y\qrcode\QRcode
{
    public function setCacheDir(string $cache_dir)
    {
        $this->cache_dir = root_path() . 'public/' . $cache_dir;
        if (!file_exists($this->cache_dir)) {
            mkdir($this->cache_dir, 0775, true);
        }
        return $this;
    }

}
