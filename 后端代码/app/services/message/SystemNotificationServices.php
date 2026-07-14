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
declare (strict_types=1);

namespace app\services\message;

use app\dao\message\SystemNotificationDao;
use app\services\BaseServices;
use app\services\serve\ServeServices;
use mohe\services\template\Template;
use mohe\services\CacheService;
use mohe\exceptions\AdminException;

/**
 *
 * Class SystemNotificationServices
 * @package app\services\system
 * @mixin SystemNotificationDao
 */
class SystemNotificationServices extends BaseServices
{

    /**
     * SystemNotificationServices constructor.
     * @param SystemNotificationDao $dao
     */
    public function __construct(SystemNotificationDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * 单个配置
     * @param int $where
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getOneNotce(array $where)
    {
        return $this->dao->getOne($where);
    }

    /**
     * 后台获取列表
     * @param $where
     */
    public function getNotList(array $where)
    {
        $industry = CacheService::get('wechat_industry', function () {
            try {
                $cache = (new Template('wechat'))->getIndustry();
                if(is_object($cache)) $cache = $cache->toArray();
                return $cache;
            } catch (\Exception $e) {
                return $e->getMessage();
            }
        }, 0) ?: [];
        !is_array($industry) && $industry = [];
        $industry['primary_industry'] = isset($industry['primary_industry']) ? $industry['primary_industry']['first_class'] . ' | ' . $industry['primary_industry']['second_class'] : '';
        $industry['secondary_industry'] = isset($industry['secondary_industry']) ? $industry['secondary_industry']['first_class'] . ' | ' . $industry['secondary_industry']['second_class'] : '';
        $list = [
            'industry' => $industry,
            'list' => $this->dao->getList($where),
        ];
        return $list;
    }

    /**
     * 获取单条数据
     * @param array $where
     * @return array
     * @throws \Psr\SimpleCache\InvalidArgumentException
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getNotInfo(array $where)
    {
        /** @var ServeServices $ServeServices */
        $ServeServices = app()->make(ServeServices::class);
        /** @var TemplateMessageServices $TemplateMessageServices */
        $TemplateMessageServices = app()->make(TemplateMessageServices::class);

        $type = $where['type'];
        unset($where['type']);
        $info = $this->dao->getOne($where);
        if (!$info) return [];
        $info = $info->toArray();
        switch ($type) {
            case 'is_system':
                $info['content'] = $info['system_text'] ?? '';
                break;
            case 'is_sms':
                $info['content'] = $info['sms_text'] ?? '';
                break;
            case 'is_wechat':
                $wechat = $TemplateMessageServices->getTemplateOne(['notification_id' => $info['id'], 'type' => 1]);
                $info['tempid'] = $wechat['tempid'] ?? '';
                $info['content'] = $wechat['content'] ?? '';
                $info['wechat_id'] = $wechat['tempkey'] ?? '';
                break;
            case 'is_routine':
                $wechat = $TemplateMessageServices->getTemplateOne(['notification_id' => $info['id'], 'type' => 0]);
                $info['tempid'] = $wechat['tempid'] ?? '';
                $info['content'] = $wechat['content'] ?? '';
                $info['routine_id'] = $wechat['tempkey'] ?? '';
                break;
        }
        return $info;
    }

    /**
     * 保存数据
     * @param array $data
     */
    public function saveData(array $data)
    {
        $type = $data['type'];
        $id = $data['id'];
        $info = $this->dao->get($id);
        if (!$info) {
            throw new AdminException('数据不存在');
        }
        /** @var TemplateMessageServices $TemplateMessageServices */
        $TemplateMessageServices = app()->make(TemplateMessageServices::class);
        $res = true;
        switch ($type) {
            case 'is_system':
                $update = [];
                $update['name'] = $data['name'];
                $update['title'] = $data['title'];
                $update['is_system'] = $data['is_system'];
                $update['is_app'] = $data['is_app'];
                $update['system_title'] = $data['system_title'];
                $update['system_text'] = $data['system_text'];
                $res = $this->dao->update((int)$id, $update);
                break;
            case 'is_sms':
                $update = [];
                $update['name'] = $data['name'];
                $update['title'] = $data['title'];
                $update['is_sms'] = $data['is_sms'];
				$update['sms_id'] = $data['sms_id'];
                $res = $this->dao->update((int)$id, $update);
                break;
            case 'is_wechat':
                $update['name'] = $data['name'];
                $update['title'] = $data['title'];
                $update['is_wechat'] = $data['is_wechat'];
                $res1 = $this->dao->update((int)$id, $update);
                $res2 = $TemplateMessageServices->update(['tempkey' => $data['wechat_id']], ['tempid' => $data['tempid']]);
                $res = $res1 && $res2;
                break;
            case 'is_routine':
                $update['name'] = $data['name'];
                $update['title'] = $data['title'];
                $update['is_routine'] = $data['is_routine'];
                $res1 = $this->dao->update((int)$id, $update);
                $res2 = $TemplateMessageServices->update(['tempkey' => $data['routine_id']], ['tempid' => $data['tempid']]);
                $res = $res1 && $res2;
                break;
            case 'is_ent_wechat':
                $update['name'] = $data['name'];
                $update['title'] = $data['title'];
                $update['is_ent_wechat'] = $data['is_ent_wechat'];
                $update['ent_wechat_text'] = $data['ent_wechat_text'];
                $update['url'] = $data['url'];
                $res = $this->dao->update((int)$id, $update);
                break;
        }
        return $res;
    }


    /**
     * 清除模版、消息缓存
     * @return bool
     */
    public function clearTemplateCache()
    {
        CacheService::handler('TEMPLATE')->clear();
        CacheService::redisHandler('NOTCEINFO')->clear();
        return true;
    }
}
