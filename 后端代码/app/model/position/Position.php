<?php
namespace app\model\position;

use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;

/**
 *  文章Model
 * Class Article
 * @package app\model\article
 */
class Position extends BaseModel
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
    protected $name = 'position';

    /**
     * 岗位表仅保留 Unix 时间戳 update_time；避免 ORM 将整型值按日期字符串解析。
     * 保持编辑岗位时仍自动写入更新时间。
     *
     * @var string|bool
     */
    protected $autoWriteTimestamp = 'int';

    /** @var bool */
    protected $createTime = false;

    /** @var string */
    protected $updateTime = 'update_time';

    /** @var bool */
    protected $dateFormat = false;


}
