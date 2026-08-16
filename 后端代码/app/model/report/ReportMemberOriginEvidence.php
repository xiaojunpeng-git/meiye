<?php

namespace app\model\report;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class ReportMemberOriginEvidence extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'cashier_v3_report_member_origin_evidence';
    protected $autoWriteTimestamp = false;
}
