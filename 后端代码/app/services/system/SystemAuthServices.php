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

namespace app\services\system;


use app\services\BaseServices;
use mohe\exceptions\AdminException;
use mohe\services\HttpService;

/**
 * 商业授权
 * Class SystemAuthServices
 * @package app\services\system
 */
class SystemAuthServices extends BaseServices
{

    /**
     * 申请授权
     * @param array $data
     * @return bool
     */
    public function authApply(array $data, $headerData)
    {
        $res = HttpService::postRequest('http://authorize.mohe.net/api/auth_apply', $data, $headerData);
        if ($res === false) {
            throw new AdminException('申请失败,服务器没有响应!');
        }
        $res = json_decode($res, true);
        if (isset($res['status'])) {
            if ($res['status'] == 400) {
                throw new AdminException($res['msg'] ?? "申请失败");
            } else {
                return true;
            }
        }
    }
}
