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
namespace app\controller\admin\v1\notification\sms;

use app\controller\admin\AuthController;
use mohe\exceptions\AdminException;
use mohe\services\sms\Sms;
use mohe\services\SystemConfigService;

/**
 * 公共短信模板
 * Class SmsPublicTemp
 * @package app\controller\admin\v1\notification\sms
 */
class SmsPublicTemp extends AuthController
{
    /**
     * @var Sms
     */
    protected $smsHandle;

    public function initialize()
    {
        parent::initialize();
        $data = SystemConfigService::more(['sms_account', 'sms_token', 'site_url']);
        $this->smsHandle = new Sms('yihaotong', $data);
        if (!$this->smsHandle->isLogin()) {
            throw new AdminException('请先填写短息配置');
        }
    }

    /**
     * 异步获取公共模板列表
     */
    public function index()
    {
        $where = $this->request->getMore([
            ['is_have', ''],
            ['page', 1],
            ['limit', 20],
        ]);
        $templateList = $this->smsHandle->publictemp($where);
        if ($templateList['status'] == 400) return $this->fail($templateList['msg']);
        $arr = $templateList['data']['data'];
        foreach ($arr as $key => $value) {
            switch ($value['type']) {
                case 1:
                    $arr[$key]['type'] = '验证码';
                    break;
                case 2:
                    $arr[$key]['type'] = '通知';
                    break;
                case 3:
                    $arr[$key]['type'] = '推广';
                    break;
                default:
                    $arr[$key]['type'] = '';
                    break;
            }
        }
        $templateList['data']['data'] = $arr;
        return $this->success($templateList['data']);
    }

}
