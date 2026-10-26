<?php

namespace app\model\report;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class ReportMemberStoreAssignmentPeriod extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'cashier_v3_report_member_store_assignment_period';
    protected $autoWriteTimestamp = false;
}
