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
 * 系统更新日志
 * Class SystemChangelog
 * @package app\model\system
 */
class SystemChangelog extends BaseModel
{
    use ModelTrait;

    protected $pk = 'id';

    protected $name = 'system_changelog';

    protected $autoWriteTimestamp = false;

    protected $createTime = false;

    protected $updateTime = false;

    /**
     * 关联变更明细
     * @return \think\model\relation\HasMany
     */
    public function items()
    {
        return $this->hasMany(SystemChangelogItem::class, 'changelog_id', 'id')->order('sort asc,id asc');
    }

    /**
     * 状态搜索器
     * @param $query
     * @param $value
     */
    public function searchStatusAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('status', (int)$value);
        }
    }

    /**
     * 标题搜索器
     * @param $query
     * @param $value
     */
    public function searchTitleAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->whereLike('title', '%' . trim((string)$value) . '%');
        }
    }

    /**
     * 版本号搜索器
     * @param $query
     * @param $value
     */
    public function searchVersionAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->whereLike('version', '%' . trim((string)$value) . '%');
        }
    }

    /**
     * 展示端搜索器（FIND_IN_SET）
     * @param $query
     * @param $value
     */
    public function searchPlatformsAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->whereRaw('FIND_IN_SET(?, platforms)', [trim((string)$value)]);
        }
    }

    /**
     * 发布日期搜索器（精确 YYYY-MM-DD）
     * @param $query
     * @param $value
     */
    public function searchPublishDateAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('publish_date', trim((string)$value));
        }
    }

    /**
     * 发布日期起始（含）
     * @param $query
     * @param $value
     */
    public function searchPublishDateStartAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('publish_date', '>=', trim((string)$value));
        }
    }

    /**
     * 发布日期截止（含）
     * @param $query
     * @param $value
     */
    public function searchPublishDateEndAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('publish_date', '<=', trim((string)$value));
        }
    }

    /**
     * 发布日期最小值（关键词默认近90天）
     * @param $query
     * @param $value
     */
    public function searchPublishDateMinAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('publish_date', '>=', trim((string)$value));
        }
    }

    /**
     * 部署批次键搜索器
     * @param $query
     * @param $value
     */
    public function searchReleaseKeyAttr($query, $value)
    {
        if ($value !== '' && $value !== null) {
            $query->where('release_key', trim((string)$value));
        }
    }

    /**
     * 关键词搜索器（标题/摘要/版本/明细内容）
     * @param $query
     * @param $value
     */
    public function searchKeywordAttr($query, $value)
    {
        if ($value === '' || $value === null) {
            return;
        }
        $keyword = trim((string)$value);
        if ($keyword === '') {
            return;
        }
        $like = '%' . $keyword . '%';
        $query->where(function ($q) use ($like, $keyword) {
            $q->whereLike('title|summary|version', $like)
                ->whereOr('id', 'in', function ($sub) use ($like) {
                    $sub->name('system_changelog_item')
                        ->whereLike('content|module_name', $like)
                        ->field('changelog_id');
                });
        });
    }
}
