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

namespace app\model\system\log;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 日志模型
 * Class SystemLog
 * @package app\model\system\log
 */
class SystemLog extends BaseModel
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
    protected $name = 'system_log';

    protected $insert = ['add_time'];

    protected function setAddTimeAttr()
    {
        return time();
    }

    /**
     * @param $query
     * @param $value
     */
    public function searchStoreIdAttr($query, $value)
    {
        if ($value !== '') $query->where('store_id', $value);
    }

    /**
     * 访问方式搜索器
     * @param Model $query
     * @param $value
     */
    public function searchPagesAttr($query, $value)
    {
		if ($value !== '') $query->whereLike('page', '%' . $value . '%');
    }

    /**
     * 访问路径搜索器
     * @param Model $query
     * @param $value
     */
    public function searchPathAttr($query, $value)
    {
		if ($value !== '') $query->whereLike('path', '%' . $value . '%');
    }

    /**
     * ip搜索器
     * @param Model $query
     * @param $value
     */
    public function searchIpAttr($query, $value)
    {
		if ($value !== '') $query->where('ip', 'LIKE', "%$value%");
    }

    /**
     * 管理员id搜索器
     * @param Model $query
     * @param $value
     */
    public function searchAdminIdAttr($query, $value)
    {
		if ($value !== '') $query->whereIn('admin_id', $value);
    }
}
