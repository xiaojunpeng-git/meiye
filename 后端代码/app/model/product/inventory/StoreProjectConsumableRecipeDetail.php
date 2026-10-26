<?php
declare(strict_types=1);

namespace app\model\product\inventory;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 项目耗材配方明细（院装）
 */
class StoreProjectConsumableRecipeDetail extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'store_project_consumable_recipe_detail';

    /** add_time 为整型时间戳，禁止 ORM 按日期字符串格式化 */
    protected $autoWriteTimestamp = false;
    protected $createTime = false;
    protected $updateTime = false;
}
