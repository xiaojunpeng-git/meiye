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


use app\dao\product\shipping\ShippingTemplatesRegionDao;
use app\services\BaseServices;
use mohe\exceptions\AdminException;
use mohe\services\CacheService;
use mohe\utils\Arr;

/**
 * 指定邮费
 * Class ShippingTemplatesRegionServices
 * @package app\services\product\shipping
 * @mixin ShippingTemplatesRegionDao
 */
class ShippingTemplatesRegionServices extends BaseServices
{
    /**
     * 构造方法
     * ShippingTemplatesRegionServices constructor.
     * @param ShippingTemplatesRegionDao $dao
     */
    public function __construct(ShippingTemplatesRegionDao $dao)
    {
        $this->dao = $dao;
    }

    /**
     * @param array $tempIds
     * @param array $cityIds
     * @param int $expire
     * @return bool|mixed|null
     */
    public function getTempRegionListCache(array $tempIds, array $cityIds, int $expire = 60)
    {
        return CacheService::redisHandler('apiShipping')->remember(md5('RegionList' . json_encode([$tempIds, $cityIds])), function () use ($tempIds, $cityIds) {
            return $this->dao->getTempRegionList($tempIds, $cityIds, 'temp_id,first,first_price,continue,continue_price', 'temp_id');
        }, $expire);
    }

    /**
     * 添加运费信息
     * @param array $regionInfo
     * @param int $group
     * @param int $tempId
     * @return bool
     * @throws \Exception
     */
    public function saveRegionV1(array $regionInfo, int $group = 0, $tempId = 0)
    {
        $res = true;
        if ($tempId) {
            if ($this->dao->count(['temp_id' => $tempId])) {
                $res = $this->dao->delete($tempId, 'temp_id');
            }
        }
        $regionList = [];
        mt_srand();
        foreach ($regionInfo as $item) {
            $uniqid = uniqid('adminapi') . rand(1000, 9999);
            if (isset($item['city_ids']) && $item['city_ids']) {
                foreach ($item['city_ids'] as $value) {
                    $regionList[] = [
                        'temp_id' => $tempId,
                        'city_id' => $value ? $value[count($value) - 1] : 0,
                        'value' => json_encode($value),
                        'first' => $item['first'] ?? 0,
                        'first_price' => $item['first_price'] ?? 0,
                        'continue' => $item['continue'] ?? 0,
                        'continue_price' => $item['continue_price'] ?? 0,
                        'group' => $group,
                        'uniqid' => $uniqid,
                    ];
                }
            }
        }
        return $res && $this->dao->saveAll($regionList);
    }

    /**
     * @param int $tempId
     * @return array
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\DbException
     * @throws \think\db\exception\ModelNotFoundException
     */
    public function getRegionListV1(int $tempId)
    {
        $freeList = $this->dao->getShippingList(['temp_id' => $tempId]);
        return Arr::formatShipping($freeList);
    }

}
