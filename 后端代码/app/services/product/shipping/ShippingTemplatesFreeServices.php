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

namespace app\services\product\shipping;


use app\dao\product\shipping\ShippingTemplatesFreeDao;
use app\services\BaseServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use mohe\utils\Arr;

/**
 * 包邮
 * Class ShippingTemplatesFreeServices
 * @package app\services\product\shipping
 * @mixin ShippingTemplatesFreeDao
 */
class ShippingTemplatesFreeServices extends BaseServices
{
    /**
     * 构造方法
     * ShippingTemplatesFreeServices constructor.
     * @param ShippingTemplatesFreeDao $dao
     */
    public function __construct(ShippingTemplatesFreeDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * @param array $tempIds
     * @param array $cityIds
     * @param int $expire
     * @return bool|mixed|null
     */
    public function isFreeListCache(array $tempIds, array $cityIds, int $expire = 60)
    {
        return CacheService::redisHandler('apiShipping')->remember(md5('isFreeList' . json_encode([$tempIds, $cityIds])), function () use ($tempIds, $cityIds) {
            return $this->dao->isFreeList($tempIds, $cityIds, 0, 'temp_id,number,price', 'temp_id');
        }, $expire);
    }

    /**
     * 添加包邮信息
     * @param array $appointInfo
     * @param int $group
     * @param int $tempId
     * @return bool
     * @throws \Exception
     */
    public function saveFreeV1(array $appointInfo, int $group = 0, int $tempId = 0)
    {
        $res = true;
        if ($tempId) {
            if ($this->dao->count(['temp_id' => $tempId])) {
                $res = $this->dao->delete($tempId, 'temp_id');
            }
        }
        $placeList = [];
        mt_srand();
        foreach ($appointInfo as $item) {
            $uniqid = uniqid('adminapi') . rand(1000, 9999);
            foreach ($item['city_ids'] as $cityId) {
                $placeList [] = [
                    'temp_id' => $tempId,
                    'city_id' => $cityId[count($cityId) - 1],
                    'value' => json_encode($cityId),
                    'number' => $item['number'] ?? 0,
                    'price' => $item['price'] ?? 0,
                    'group' => $group,
                    'uniqid' => $uniqid,
                ];
            }
        }
        if (count($placeList)) {
            return $res && $this->dao->saveAll($placeList);
        } else {
            return $res;
        }
    }

    /**
     * @param int $tempId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getFreeListV1(int $tempId)
    {
        $freeList = $this->dao->getShippingList(['temp_id' => $tempId]);
        return Arr::formatShipping($freeList);
    }

}
