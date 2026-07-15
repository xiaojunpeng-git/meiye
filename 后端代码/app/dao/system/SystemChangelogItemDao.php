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

namespace app\dao\system;

use app\dao\BaseDao;
use app\model\system\SystemChangelogItem;

/**
 * 系统更新日志明细
 * Class SystemChangelogItemDao
 * @package app\dao\system
 */
class SystemChangelogItemDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SystemChangelogItem::class;
    }

    /**
     * 批量按主日志ID获取明细（避免 N+1）
     * @param array $changelogIds
     * @return array<int, array>
     */
    public function getByChangelogIds(array $changelogIds): array
    {
        if (!$changelogIds) {
            return [];
        }
        $list = $this->getModel()
            ->whereIn('changelog_id', $changelogIds)
            ->order('sort asc,id asc')
            ->select()
            ->toArray();
        $grouped = [];
        foreach ($list as $item) {
            $grouped[(int)$item['changelog_id']][] = $item;
        }
        return $grouped;
    }

    /**
     * 按主日志ID获取明细
     * @param int $changelogId
     * @return array
     */
    public function getByChangelogId(int $changelogId): array
    {
        return $this->search(['changelog_id' => $changelogId])->order('sort asc,id asc')->select()->toArray();
    }

    /**
     * 删除主日志下全部明细
     * @param int $changelogId
     * @return void
     */
    public function deleteByChangelogId(int $changelogId): void
    {
        $this->getModel()->where('changelog_id', $changelogId)->delete();
    }
}
