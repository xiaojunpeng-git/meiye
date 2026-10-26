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


use app\dao\product\shipping\ShippingTemplatesNoDeliveryDao;
use app\services\BaseServices;
use mohe\exceptions\AdminException;
use mohe\utils\Arr;

/**
 * 不送达
 * Class ShippingTemplatesNoDeliveryServices
 * @package app\services\product\shipping
 * @mixin ShippingTemplatesNoDeliveryDao
 */
class ShippingTemplatesNoDeliveryServices extends BaseServices
{
    /**
     * 构造方法
     * ShippingTemplatesNoDeliveryServices constructor.
     * @param ShippingTemplatesNoDeliveryDao $dao
     */
    public function __construct(ShippingTemplatesNoDeliveryDao $dao)
    {
        $this->dao = $dao;
    }


    /**
     * 添加不送达信息
     * @param array $noDeliveryInfo
     * @param int $tempId
     * @return bool|mixed
     */
    public function saveNoDeliveryV1(array $noDeliveryInfo, int $tempId = 0)
    {
        $res = true;
        if ($tempId) {
            if ($this->dao->count(['temp_id' => $tempId])) {
                $res = $this->dao->delete($tempId, 'temp_id');
            }
        }
        $placeList = [];
        mt_srand();
        foreach ($noDeliveryInfo as $item) {
            $uniqid = uniqid('adminapi') . rand(1000, 9999);
            foreach ($item['city_ids'] as $cityId) {
                $placeList [] = [
                    'temp_id' => $tempId,
                    'city_id' => $cityId[count($cityId) - 1],
                    'value' => json_encode($cityId),
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
    public function getNoDeliveryListV1(int $tempId)
    {
        $freeList = $this->dao->getShippingList(['temp_id' => $tempId]);
        return Arr::formatShipping($freeList);
    }


}
