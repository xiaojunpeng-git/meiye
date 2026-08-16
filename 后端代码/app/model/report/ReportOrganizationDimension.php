<?php

namespace app\model\report;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class ReportOrganizationDimension extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'cashier_v3_report_organization_dimension';
    protected $autoWriteTimestamp = false;
}
