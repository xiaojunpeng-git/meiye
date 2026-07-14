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

declare(strict_types=1);

namespace app\dao\other;

use app\dao\BaseDao;
use app\model\other\StoreGiftConfig;

/**
 * 赠送配置
 */
class StoreGiftConfigDao extends BaseDao
{
    protected function setModel(): string
    {
        return StoreGiftConfig::class;
    }

    /**
     * 批量获取指定类型的赠送配置
     */
    public function getConfigMap(int $giftType, array $relationIds): array
    {
        if (!$relationIds) {
            return [];
        }
        return $this->search([
            'gift_type' => $giftType,
            'relation_id' => $relationIds,
        ])->column('config', 'relation_id');
    }
}
