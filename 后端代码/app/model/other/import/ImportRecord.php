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

namespace app\model\other\import;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * Class Import
 * @package app\model\other
 */
class ImportRecord extends BaseModel
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
    protected $name = 'import_record';

    /**
     * 类型搜索器
     * @param $query
     * @param $value
     * @return void
     */
    public function searchTypeAttr($query, $value)
    {
        if ($value !== '') {
            if (is_array($value)) {
                $query->whereIn('type', $value);
            } else {
                $query->where('type', $value);
            }
        }
    }

    /**
     * 类型搜索器
     * @param $query
     * @param $value
     * @return void
     */
    public function searchImportTypeAttr($query, $value)
    {
        if ($value !== '') {
            if (is_array($value)) {
                $query->whereIn('import_type', $value);
            } else {
                $query->where('import_type', $value);
            }
        }
    }

    /**
     * 状态搜索器
     * @param $query
     * @param $value
     * @return void
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '') {
            if (is_array($value)) {
                $query->whereIn('status', $value);
            } else {
                $query->where('status', $value);
            }
        }
    }

    /**
     * 名称搜索器
     * @param $query
     * @param $value
     * @return void
     */
    public function searchNameAttr($query, $value)
    {
        if ($value !== '') {
            $query->whereLike('name', "%{$value}%");
        }
    }

    /**
     * 删除搜索器
     * @param $query
     * @param $value
     * @return void
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('is_del', $value);
        }
    }

    /**
     * 归属门店/供应商
     */
    public function searchRelationIdAttr($query, $value)
    {
        if ($value !== '') {
            $query->where('relation_id', $value);
        }
    }

}
