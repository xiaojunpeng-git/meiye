<?php
namespace app\model\agent;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 区域管理人员管辖门店
 */
class SystemRegionAgentStore extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';
    protected $name = 'system_region_agent_store';
}
