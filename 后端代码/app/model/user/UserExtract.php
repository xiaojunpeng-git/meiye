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

namespace app\model\user;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\model;

/**
 * Class UserExtract
 * @package app\model\user
 */
class UserExtract extends BaseModel
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
    protected $name = 'user_extract';

    //审核中
    const AUDIT_STATUS = 0;
    //未通过
    const FAIL_STATUS = -1;
    //已提现
    const SUCCESS_STATUS = 1;

    /**
     * 状态
     * @var string[]
     */
    protected static $status = [
        -1 => '未通过',
        0 => '审核中',
        1 => '已提现',
        2 => '已收款',
    ];

    /**
     * 关联user
     * @return model\relation\HasOne
     */
    public function user()
    {
        return $this->hasOne(User::class, 'uid', 'uid');
    }

    /**
     * 用户uid
     * @param Model $query
     * @param $value
     */
    public function searchUidAttr($query, $value)
    {
        if (is_array($value))
            $query->whereIn('uid', $value);
        else
            $query->where('uid', $value);
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
		if (is_array($value)) {
			if ($value) {
				$query->whereIn('status', $value);
			}
		}else {
			if ($value !== '') {
				$query->where('status', $value);
			}
		}
    }

	/**
	 * 微信v3转账状态码
	 * @param Model $query
	 * @param $value
	 */
	public function searchWechatStateAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) {
				$query->whereIn('wechat_state', $value);
			}
		}else {
			if ($value !== '') {
				$query->where('wechat_state', $value);
			}
		}
	}

    /**
     * 模糊搜索
     * @param Model $query
     * @param $value
     */
    public function searchLikeAttr($query, $value)
    {
        if ($value) {
            $query->where(function ($query) use ($value) {
                $query->where('real_name|id|bank_code|alipay_code', 'LIKE', "%$value%")->whereOr('uid', 'in', function ($query) use ($value) {
                    $query->name('user')->whereLike('nickname', '%' . $value . '%')->field('uid')->select();
                });
            });
        }
    }

}
