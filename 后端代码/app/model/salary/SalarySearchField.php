<?php

namespace app\model\salary;

use mohe\traits\ModelTrait;
use mohe\basic\BaseModel;

/**
 * 报表自定义搜索项
 */
class SalarySearchField extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'salary_search_field';
}
