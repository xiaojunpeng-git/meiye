<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2020 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------
declare (strict_types=1);

namespace app\model\spread;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;
use think\Model;

/**
 * 分销员等级任务
 * Class AgentLevelTask
 * @package app\model\agent
 */
class AgentLevelTask extends BaseModel
{

    use ModelTrait;

    /**
     * 数据表主键
     * @var string
     */
    protected $pk = 'id';

    /**
     * 模型名称
     * @var string
     */
    protected $name = 'agent_level_task';

    /**
     * 关联分销员等级
     * @return \think\model\relation\HasOne
     */
    public function level()
    {
        return $this->hasOne(AgentLevel::class, 'id', 'level_id');
    }

    /**
     * 关联任务完成记录
     * @return \think\model\relation\HasMany
     */
    public function record()
    {
        return $this->hasMany(AgentLevelTaskRecord::class, 'task_id', 'id');
    }

    /**
     * 关键词搜索器
     * @param $query Model
     * @param $value
     */
    public function searchKeywordAttr($query, $value)
    {
        if ($value !== '') $query->where('id|name|desc', 'like', '%' . $value . '%');
    }

    /**
     * 任务类型搜索器
     * @param $query Model
     * @param $value
     */
    public function searchTypeAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('type', $value);
        } else {
            if ($value !== '') $query->where('type', $value);
        }

    }

    /**
     * 分销员等级搜索器
     * @param $query Model
     * @param $value
     */
    public function searchLevelIdAttr($query, $value)
    {
        if (is_array($value)) {
            $query->whereIn('Level_id', $value);
        } else {
            if ($value !== '') $query->where('Level_id', $value);
        }

    }


    /**
     * 状态搜索器
     * @param $query Model
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '') $query->where('status', $value);
    }

    /**
     * 是否删除搜索器
     * @param $query Model
     * @param $value
     */
    public function searchIsDelAttr($query, $value)
    {
        if ($value !== '') $query->where('is_del', $value);
    }
}
