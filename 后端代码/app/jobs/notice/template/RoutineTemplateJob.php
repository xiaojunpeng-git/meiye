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
use mohe\services\wechat\MiniProgram;
use mohe\traits\QueueTrait;
use mohe\services\template\Template;
use think\facade\Log;

/**
 * 小程序模板消息消息队列
 * Class RoutineTemplateJob
 * @package app\jobs
 */
class RoutineTemplateJob extends BaseJobs
{
    use QueueTrait;

    /**
     * 同步订阅消息
     * @return bool
     */
    public function doJob(array $template, array $errMessage)
    {
        $time = time();

        $works = [];
        try {
            $works = MiniProgram::getSubscribeTemplateKeyWords($template['tempkey']);
        } catch (\Throwable $e) {
            $wechatErr = $e->getMessage();
            if (is_string($wechatErr)) {
                Log::error('同步订阅消息,获取关键词列表失败：' . $wechatErr);
            } elseif (in_array($wechatErr->getCode(), array_keys($errMessage))) {
                Log::error('同步订阅消息,获取关键词列表失败：' . $wechatErr->getMessage());
            }
        }
        $kid = [];
        if ($works) {
            $works = array_combine(array_column($works, 'name'), $works);
            $content = is_array($template['content']) ? $template['content'] : explode("\n", $template['content']);
            foreach ($content as $c) {
                $name = explode('{{', $c)[0] ?? '';
                if ($name && isset($works[$name])) {
                    $kid[] = $works[$name]['kid'];
                }
            }
        }
        if ($kid && isset($template['kid']) && !$template['kid']) {
            $tempid = '';
            try {
                $tempid = MiniProgram::addSubscribeTemplate($template['tempkey'], $kid, $template['name']);
            } catch (\Throwable $e) {
                $wechatErr = $e->getMessage();
                if (is_string($wechatErr)) {
                    Log::error('同步订阅消息,添加订阅消息模版失败：' . $wechatErr);
                } elseif (in_array($wechatErr->getCode(), array_keys($errMessage))) {
                    Log::error('同步订阅消息,添加订阅消息模版失败：' . $wechatErr->getMessage());
                }
            }
            if ($tempid != $template['tempid']) {
                /** @var TemplateMessageServices $templateMessageServices */
                $templateMessageServices = app()->make(TemplateMessageServices::class);
                $templateMessageServices->update($template['id'], ['tempid' => $tempid, 'kid' => json_encode($kid), 'add_time' => $time], 'id');
            }
        }

        return true;
    }


}
