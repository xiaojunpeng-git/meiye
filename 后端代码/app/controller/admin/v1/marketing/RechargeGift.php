<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\controller\admin\v1\marketing;

use app\controller\admin\AuthController;
use app\services\other\StoreGiftConfigServices;
use app\services\system\config\SystemGroupDataServices;

/**
 * 储值档位赠送配置
 */
class RechargeGift extends AuthController
{
    /**
     * 获取储值档位赠送配置
     */
    public function read($id, SystemGroupDataServices $groupDataServices, StoreGiftConfigServices $giftServices)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->fail('参数错误');
        }
        if (!$groupDataServices->get($id)) {
            return $this->fail('储值档位不存在');
        }
        return $this->success($giftServices->getConfig(StoreGiftConfigServices::GIFT_TYPE_RECHARGE, $id));
    }

    /**
     * 保存储值档位赠送配置
     */
    public function save($id, SystemGroupDataServices $groupDataServices, StoreGiftConfigServices $giftServices)
    {
        $id = (int)$id;
        if (!$id) {
            return $this->fail('参数错误');
        }
        if (!$groupDataServices->get($id)) {
            return $this->fail('储值档位不存在');
        }
        [$sendAll] = $this->request->postMore([
            ['sendAll', ['product' => [], 'coupon' => []]],
        ], true);
        $giftServices->saveConfig(StoreGiftConfigServices::GIFT_TYPE_RECHARGE, $id, $sendAll);
        return $this->success('保存成功');
    }
}
