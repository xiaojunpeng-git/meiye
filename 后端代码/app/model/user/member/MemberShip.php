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

namespace app\model\user\member;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * Class MemberShip
 * @package app\model\user\member
 */
class MemberShip extends BaseModel
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
    protected $name = 'member_ship';

	/**
	 * @param Model $query
	 * @param $value
	 */
	public function searchIsDelAttr($query, $value)
	{
		if ($value !== '') $query->where('is_del', $value);
	}
}
