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

namespace app\model\store;

use app\model\user\User;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 门店用户
 * Class StoreUser
 * @package app\model\store
 */
class StoreUser extends BaseModel
{
    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'store_user';

    /**
     * user用户表一对一关联
     * @return \think\model\relation\HasOne
     */
    public function user()
    {
        return $this->hasOne(User::class, 'uid', 'uid');
    }

    /**
     * 门店id搜索器
     * @param $query
     * @param $value
     */
    public function searchStoreIdAttr($query, $value)
    {
        if ($value && $value != -1) {
			if (is_array($value)) {
				$query->whereIn('store_id', $value);
			} else {
				$query->where('store_id', $value);
			}
        }
    }

    /**
     * 关联标签修改器
     * @param $value
     * @return false|string
     */
    protected function setLabelIdAttr($value)
    {
        if ($value) {
            return is_array($value) ? implode(',', $value) : $value;
        }
        return '';
    }

    /**
     * 关联标签获取器
     * @param $value
     * @param $data
     * @return mixed
     */
    protected function getLabelIdAttr($value)
    {
        if ($value) {
            return is_string($value) ? explode(',', $value) : $value;
        }
        return [];
    }


    /**
     * uid搜索器
     * @param Model $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('uid', $value);
        } else {
            if ($value) {
                $query->where('uid', $value);
            }
        }
    }


}
