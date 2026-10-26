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

namespace app\model\system;

use mohe\basic\BaseModel;
use mohe\traits\ModelTrait;

/**
 * 系统更新日志审计
 * Class SystemChangelogAudit
 * @package app\model\system
 */
class SystemChangelogAudit extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'system_changelog_audit';

    protected $autoWriteTimestamp = false;

    protected $createTime = false;

    protected $updateTime = false;

    /**
     * 主日志ID搜索器
     * @param $query
     * @param $value
     */
    public function searchChangelogIdAttr($query, $value)
    {
        if ($value !== '' && $value !== null && (int)$value > 0) {
            $query->where('changelog_id', (int)$value);
        }
    }

    /**
     * 动作搜索器
     * @param $query
     * @param $value
     */
    public function searchActionAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('action', trim((string)$value));
        }
    }
}
