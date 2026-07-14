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

namespace app\services\message\sms;


use app\dao\system\config\SystemConfigDao;
use app\services\BaseServices;

/**
 * 短信平台注册登录
 * Class SmsAdminServices
 * @package app\services\message\sms
 * @mixin SystemConfigDao
 */
class SmsAdminServices extends BaseServices
{
    /**
     * 构造方法
     * SmsAdminServices constructor.
     * @param SystemConfigDao $dao
     */
    public function __construct(SystemConfigDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 更新短信配置
     * @param string $account
     * @param string $password
     * @return mixed
     */
    public function updateSmsConfig(string $account, string $password)
    {
        return $this->transaction(function () use ($account, $password) {
            $this->dao->update('sms_account', ['value' => json_encode($account)], 'menu_name');
            $this->dao->update('sms_token', ['value' => json_encode($password)], 'menu_name');
            \mohe\services\SystemConfigService::clear();
        });
    }


}
