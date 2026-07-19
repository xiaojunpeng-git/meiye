<?php
namespace app\dao\organization;

use app\dao\BaseDao;
use app\model\organization\OrganizationWriteIdempotency;

class OrganizationWriteIdempotencyDao extends BaseDao
{
    protected function setModel(): string
    {
        return OrganizationWriteIdempotency::class;
    }
}
