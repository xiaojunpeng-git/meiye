<?php
namespace app\dao\organization;

use app\dao\BaseDao;
use app\model\organization\OrganizationAdminStoreExclude;

class OrganizationAdminStoreExcludeDao extends BaseDao
{
    protected function setModel(): string
    {
        return OrganizationAdminStoreExclude::class;
    }
}
