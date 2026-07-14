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

namespace app\dao\store;


use app\dao\BaseDao;
use app\model\store\StoreConfig;

//use mohe\traits\SearchDaoTrait;

/**
 * Class StoreConfigDao
 * @package app\dao\store
 */
class StoreConfigDao extends BaseDao
{

//    use SearchDaoTrait;

    /**
     * @return string
     */
    protected function setModel(): string
    {
        return StoreConfig::class;
    }

	/**
	 * 获取门店配置
	 * @param array $keys
	 * @param int $type
	 * @param int $relation_id
	 * @param int $printId
	 * @return \mohe\basic\BaseModel|mixed|\think\Model
	 */
    public function searchs(array $keys = [], int $type = 0, int $relation_id = 0, int $printId = 0)
    {
        return parent::search()->when($keys, function($query) use($keys) {
			$query->whereIn('key_name', $keys);
		})->where('type', $type)->where('relation_id', $relation_id)->where('print_id', $printId);
    }
}
