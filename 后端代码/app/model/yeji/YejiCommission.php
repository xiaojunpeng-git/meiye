<?php
namespace app\model\yeji;

use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;

/**
 *  文章Model
 * Class Article
 * @package app\model\article
 */
class YejiCommission extends BaseModel
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
    protected $name = 'yeji_commission';


}
