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

namespace app\jobs\notice;

use mohe\basic\BaseJobs;
use mohe\services\HttpService;
use mohe\traits\QueueTrait;
use think\facade\Log;

class EnterpriseWechatJob extends BaseJobs
{
    use QueueTrait;


    /**
     * 给企业微信群发送消息
     */
    public function doJob($data, $url, $ent_wechat_text)
    {
        try {
            $str = $ent_wechat_text;
            foreach ($data as $key => $item) {
                $str = str_replace('{' . $key . '}', $item, $str);
            }
            $s = explode('\n', $str);
            $d = '';
            foreach ($s as $item) {
                $d .= $item . "\n>";
            }
            $d = substr($d, 0, strlen($d) - 2);
            $datas = [
                'msgtype' => 'markdown',
                'markdown' => ['content' => $d]
            ];
            HttpService::postRequest($url, json_encode($datas));
        } catch (\Throwable $e) {
            Log::error('发送企业群消息失败,失败原因:' . $e->getMessage());
        }
		return true;

    }

}
