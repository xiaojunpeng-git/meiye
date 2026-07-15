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
use app\model\system\SystemChangelog;

/**
 * 系统更新日志
 * Class SystemChangelogDao
 * @package app\dao\system
 */
class SystemChangelogDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SystemChangelog::class;
    }

    /**
     * 后台分页列表
     * @param array $where
     * @param string $field
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getList(array $where, string $field = '*', int $page = 0, int $limit = 0): array
    {
        return $this->search($where)->field($field)->when($page && $limit, function ($query) use ($page, $limit) {
            $query->page($page, $limit);
        })->order('publish_date desc,sort desc,id desc')->select()->toArray();
    }

    /**
     * 按 release_key 获取
     * @param string $releaseKey
     * @param bool $lock 是否 FOR UPDATE（须在事务内）
     * @return array|null
     */
    public function getByReleaseKey(string $releaseKey, bool $lock = false): ?array
    {
        if ($releaseKey === '') {
            return null;
        }
        $query = $this->getModel()->where('release_key', $releaseKey);
        if ($lock) {
            $query->lock(true);
        }
        $row = $query->find();
        if (!$row) {
            return null;
        }
        return is_array($row) ? $row : $row->toArray();
    }

    /**
     * 公开端已发布分页
     * @param string $platform
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getPublishedPage(string $platform, int $page, int $limit): array
    {
        $now = time();
        $query = $this->getModel()
            ->where('status', 2)
            ->where('publish_time', '<=', $now)
            ->whereRaw('FIND_IN_SET(?, platforms)', [$platform]);

        $count = (clone $query)->count();
        $list = $query->order('publish_date desc,sort desc,id desc')
            ->when($page && $limit, function ($q) use ($page, $limit) {
                $q->page($page, $limit);
            })
            ->select()
            ->toArray();

        return compact('list', 'count');
    }

    /**
     * 公开端最新可见 publish_time
     * @param string $platform
     * @return int
     */
    public function getLatestPublishedTime(string $platform): int
    {
        $now = time();
        $time = $this->getModel()
            ->where('status', 2)
            ->where('publish_time', '<=', $now)
            ->whereRaw('FIND_IN_SET(?, platforms)', [$platform])
            ->order('publish_time desc,id desc')
            ->value('publish_time');

        return (int)($time ?: 0);
    }
}
