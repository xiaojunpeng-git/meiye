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

namespace app\model\work;


use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * Class WorkMedia
 * @package app\model\work
 */
class WorkMedia extends BaseModel
{

    use ModelTrait;

    /**
     * @var string
     */
    protected $name = 'work_media';

    /**
     * @var string
     */
    protected $key = 'id';

    /**
     * @var string
     */
    protected $autoWriteTimestamp = 'int';

    /**
     * @param $query
     * @param $value
     */
    public function searchMd5PathAttr($query, $value)
    {
        $query->where('md5_path', $value);
    }

    /**
     * @param $query
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        $query->where('type', $value);
    }

}
