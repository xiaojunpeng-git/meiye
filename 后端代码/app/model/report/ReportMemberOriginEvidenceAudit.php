<?php

namespace app\model\report;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

class ReportMemberOriginEvidenceAudit extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'cashier_v3_report_member_origin_evidence_audit';
    protected $autoWriteTimestamp = false;
}
