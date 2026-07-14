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
 * Class ImportError
 * @package app\model\other
 */
class ImportRecordError extends BaseModel
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
    protected $name = 'import_record_error';


    /**
     * 关联 id 搜索器
     * @param $query
     * @param $value
     * @return void
     * User: liusl
     * DateTime: 2024/12/9 下午2:23
     */
    public function searchRecordIdAttr($query, $value)
    {
        if ($value !== '') {
            if (is_array($value)) {
                if ($value) $query->whereIn('record_id', $value);
            } else {
                if ($value) $query->where('record_id', $value);
            }
        }
    }
}
