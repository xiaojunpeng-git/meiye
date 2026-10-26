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

namespace mohe\services\wechat\config;

/**
 * 开放平台APP配置
 * Class OpenAppConfig
 * @package mohe\services\wechat\config
 */
class OpenAppConfig extends OpenWebConfig
{

    /**
     * OpenAppConfig constructor.
     */
    public function init()
    {
        if ($this->init) {
            return;
        }
        $this->init = true;
        $this->appId = $this->appId ?: $this->config->getConfig('app.appid', '');
        $this->secret = $this->secret ?: $this->config->getConfig('app.secret', '');
        $this->token = $this->token ?: $this->config->getConfig('app.token', '');
        $this->aesKey = $this->aesKey ?: $this->config->getConfig('app.key', '');
    }
}
