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

namespace app\model\system;

use app\model\user\User;
use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 用户申请
 * Class SystemUserApply
 * @package app\model\system\admin
 */
class SystemUserApply extends BaseModel
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
    protected $name = 'system_user_apply';

	/**
	 * 多图
	 * @param $value
	 * @return string
	 */
	public function setImagesAttr($value)
	{
		if ($value) {
			return is_array($value) ? json_encode($value) : $value;
		}
		return '';
	}

	/**
	 * 多图获取器
	 * @param $value
	 * @return array|mixed
	 */
	public function getImagesAttr($value)
	{
		return is_string($value) ? json_decode($value, true) : [];
	}

	/**
	 * 关联user
	 * @return model\relation\HasOne
	 */
	public function user()
	{
		return $this->hasOne(User::class, 'uid', 'uid');
	}


	/**
	 * id搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchIdAttr($query, $value)
	{
		if (is_array($value)) {
			$query->whereIn('id', $value);
		} else {
			$query->where('id', $value);
		}
	}

	/**
	 * 关键词搜索器
	 * @param $query Model
	 * @param $value
	 */
	public function searchKeywordAttr($query, $value)
	{
		if ($value !== '') $query->where('id|uid|name|phone|system_name|fail_msg|mark', 'like', '%' . $value . '%');
	}

	/**
	 * uid搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchUidAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('uid', $value);
		} else {
			if($value !== '') $query->where('uid', $value);
		}
	}

	/**
	 * 商户搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchTypeAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('type', $value);
		} else {
			if ($value !== '') $query->where('type', $value);
		}
	}

	/**
	 * 关联门店ID、供应商ID搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchRelationIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('relation_id', $value);
		} else {
			if ($value !== '') $query->where('relation_id', $value);
		}
	}



    /**
     * 权限规格状态搜索器
     * @param Model $query
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== 'all') {
            $query->where('status', $value);
        }
    }

	/**
	 * 是否删除搜索器
	 * @param Model $query
	 * @param $value
	 * @param $data
	 */
	public function searchIsDelAttr($query, $value)
	{
		if ($value !== '') $query->where('is_del', $value);
	}


}
