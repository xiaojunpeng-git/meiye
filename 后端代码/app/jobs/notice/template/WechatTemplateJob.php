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

namespace app\jobs\notice\template;


use app\services\message\TemplateMessageServices;
use mohe\basic\BaseJobs;
use mohe\services\wechat\OfficialAccount;
use mohe\traits\QueueTrait;
use mohe\services\template\Template;
use think\facade\Log;
use think\facade\Route;

/**
 * 公众号模版消息
 * Class WechatTemplateJob
 * @package app\jobs
 */
class WechatTemplateJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 同步模版消息
     * @return bool
     */
    public function doJob(array $template)
    {
        if ($template) {
            try {
                /*
                 * 删除所有模版ID
                 */
                //获取微信平台已经添加的模版
                $list = OfficialAccount::getPrivateTemplates();//获取所有模版
                foreach ($list['template_list'] as $v) {
                    //删除已有模版
                    OfficialAccount::deleleTemplate($v['template_id']);
                }
                /** @var TemplateMessageServices $templateMessageServices */
                $templateMessageServices = app()->make(TemplateMessageServices::class);
                foreach ($template as $temp) {
                    $keywordList = [];
                    $content = is_array($temp['content']) ? $temp['content'] : explode("\n", $temp['content']);
                    foreach ($content as $c) {
                        $name = explode('{{', $c)[0] ?? '';
                        if ($name) {
                            $keywordList[] = $name;
                        }
                    }
                    if(!$keywordList) continue;
                    //添加模版消息
                    $res = OfficialAccount::addTemplateId($temp['tempkey'], $keywordList);
                    if (!$res['errcode'] && $res['template_id']) {
                        $templateMessageServices->update($temp['id'], ['tempid' => $res['template_id']]);
                    }
                }
            } catch (\Throwable $e) {
                Log::error('同步模版消息失败，原因：' . $e->getMessage());
            }
        }
        return true;
    }


}
