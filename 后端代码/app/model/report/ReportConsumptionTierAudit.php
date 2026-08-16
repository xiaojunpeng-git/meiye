<?php

namespace app\model\report;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class ReportConsumptionTierAudit extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'cashier_v3_report_consumption_tier_audit';
    protected $autoWriteTimestamp = false;
}
