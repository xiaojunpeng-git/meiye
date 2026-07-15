<?php
namespace app\dao\organization;

use app\dao\BaseDao;
use app\model\organization\OrganizationStore;

class OrganizationStoreDao extends BaseDao
{
    protected function setModel(): string
    {
        return OrganizationStore::class;
    }
}
