<?php
namespace app\dao\agent;

use app\dao\BaseDao;
use app\model\agent\SystemRegionAgentStore;

class SystemRegionAgentStoreDao extends BaseDao
{
    protected function setModel(): string
    {
        return SystemRegionAgentStore::class;
    }
}
