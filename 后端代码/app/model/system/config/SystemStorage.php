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

namespace app\model\system\config;


use mohe\basic\BaseModel;

/**
 * 云存储
 * Class SystemStorage
 * @package app\model\system\config
 */
class SystemStorage extends BaseModel
{

    /**
     * @var string
     */
    protected $name = 'system_storage';

    /**
     * @var string
     */
    protected $pk = 'id';

    /**
     * @var bool
     */
    protected $autoWriteTimestamp = false;

    /**
     * @param $query
     * @param $value
     */
    public function searchNameAttr($query, $value)
    {
        $query->where('name', $value);
    }

    /**
     * @param $query
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('type', $value);
        }
    }

	/**
	 * 状态搜索器
	 * @param $query
	 * @param $value
	 */
	public function searchStatusAttr($query, $value)
	{
		if ($value !== '') $query->where('status', $value);
	}
}
