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

namespace app\model\store\finance;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use app\model\store\SystemStore;
use think\Model;

/**
 * 门店列表
 * Class SystemStore
 * @package app\model\store
 */
class StoreExtract extends BaseModel
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
    protected $name = 'store_extract';

    /**
     * 状态
     * @var string[]
     */
    protected static $status = [
        -1 => '未通过',
        0 => '审核中',
        1 => '已提现'
    ];

    /**
     * 门店一对一关联
     * @return \think\model\relation\HasOne
     */
    public function store()
    {
        return $this->hasOne(SystemStore::class, 'id', 'store_id')->hidden(['bank_code,bank_address', 'alipay_account', 'alipay_qrcode_url', 'wechat', 'wechat_qrcode_url']);
    }

    /**
     * 门店id搜索器
     * @param $query
     * @param $value
     */
    public function searchStoreIdAttr($query, $value)
    {
		if (is_array($value)) {
			if ($value) $query->whereIn('store_id', $value);
		} else {
			if ($value !== '') $query->where('store_id', $value);
		}
    }

    /**
     * 提现方式
     * @param Model $query
     * @param $value
     */
    public function searchExtractTypeAttr($query, $value)
    {
        if ($value != '') $query->where('extract_type', $value);
    }

    /**
     * 审核状态
     * @param Model $query
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('status', $value);
        }
    }

    /**
     * 转账状态
     * @param Model $query
     * @param $value
     */
    public function searchPayStatusAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('pay_status', $value);
        }
    }

    /**
     * 状态驳回
     * @param Model $query
     * @param $value
     */
    public function searchNotStatusAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('status', '<>', $value);
        }
    }

}
