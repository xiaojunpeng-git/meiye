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

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 自定义快捷菜单模型
 * Class SystemCustomMenus
 * @package app\model\system
 */
class SystemCustomMenus extends BaseModel
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
    protected $name = 'system_custom_menus';


	/**
	 * 管理员搜索器
	 * @param Model $query
	 * @param $value
	 */
	public function searchAdminIdAttr($query, $value)
	{
		if (is_array($value)) {
			if ($value) $query->whereIn('admin_id', $value);
		} else {
			if ($value != '') $query->where('admin_id', $value);
		}
	}

    /**
     * 是否显示搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIsShowAttr($query, $value)
    {
        if ($value != '') {
            $query->where('is_show', $value);
        }
    }


}
