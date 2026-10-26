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

namespace app\services\system;

use app\dao\system\SystemChangelogAuditDao;
use app\dao\system\SystemChangelogDao;
use app\dao\system\SystemChangelogItemDao;
use app\services\BaseServices;
use mohe\exceptions\AdminException;
use mohe\exceptions\ApiException;

/**
 * 系统更新日志
 * Class SystemChangelogServices
 * @package app\services\system
 * @mixin SystemChangelogDao
 */
class SystemChangelogServices extends BaseServices
{
    public const STATUS_DRAFT = 0;
    public const STATUS_PENDING = 1;
    public const STATUS_PUBLISHED = 2;
    public const STATUS_OFFLINE = 3;

    /** @var string[] 合法展示端 */
    public const PLATFORMS = ['admin', 'cashier', 'mini', 'store'];

    /** @var string[] 变更类型 */
    public const CHANGE_TYPES = ['add', 'adjust', 'fix', 'offline', 'optimize'];

    /** @var string[] 状态中文 */
    public $statusName = [
        0 => '草稿',
        1 => '待发布',
        2 => '已发布',
        3 => '已下架',
    ];

    /** @var string[] 变更类型中文 */
    public $changeTypeName = [
        'add' => '新增',
        'optimize' => '优化',
        'adjust' => '调整',
        'fix' => '修复',
        'offline' => '下线',
    ];

    /** @var string[] 展示端中文 */
    public $platformName = [
        'mini' => '小程序',
        'admin' => '平台后台',
        'store' => '门店后台',
        'cashier' => '收银台',
    ];

    /** @var SystemChangelogItemDao */
    protected $itemDao;

    /** @var SystemChangelogAuditDao */
    protected $auditDao;

    /**
     * SystemChangelogServices constructor.
     * @param SystemChangelogDao $dao
     * @param SystemChangelogItemDao $itemDao
     * @param SystemChangelogAuditDao $auditDao
     */
    public function __construct(
        SystemChangelogDao $dao,
        SystemChangelogItemDao $itemDao,
        SystemChangelogAuditDao $auditDao
    ) {
        $this->dao = $dao;
        $this->itemDao = $itemDao;
        $this->auditDao = $auditDao;
    }

    /**
     * 后台分页列表
     * @param array $where
     * @return array
     */
    public function getAdminList(array $where): array
    {
        $where = $this->prepareAdminWhere($where);
        [$page, $limit] = $this->getPageValue();
        $list = $this->dao->getList($where, '*', $page, $limit);
        $count = $this->dao->count($where);
        if ($list) {
            $this->attachListExtras($list);
        }
        return [
            'list' => $list,
            'count' => $count,
            'status_map' => $this->statusName,
            'change_type_map' => $this->changeTypeName,
            'platform_map' => $this->platformName,
        ];
    }

    /**
     * 详情
     * @param int $id
     * @param bool $withAudit
     * @return array
     */
    public function getDetail(int $id, bool $withAudit = false): array
    {
        $info = $this->getChangelogOrFail($id);
        $info = is_array($info) ? $info : $info->toArray();
        $info['items'] = $this->itemDao->getByChangelogId($id);
        $info['platforms_arr'] = $this->platformsToArray($info['platforms'] ?? '');
        $info['type_summary'] = $this->buildTypeSummary($info['items']);
        $info['status_text'] = $this->statusName[(int)$info['status']] ?? '';
        if ($withAudit) {
            $info['audit_list'] = $this->auditDao->getByChangelogId($id);
        }
        return $info;
    }

    /**
     * 保存草稿或待发布（已发布编辑也允许但写审计）
     * @param array $data
     * @param int $adminId
     * @param int $id
     * @param bool $useTran 是否自行开事务；外层已有事务时传 false
     * @return int
     */
    public function saveDraft(array $data, int $adminId, int $id = 0, bool $useTran = true): int
    {
        $items = $data['items'] ?? [];
        unset($data['items']);
        $this->validateMainData($data, $items);

        $platforms = $this->normalizePlatforms($data['platforms'] ?? '');
        // 仅当明确传入 status 时才改状态，避免 upsert 把待发布降级为草稿
        $statusExplicit = array_key_exists('status', $data);
        $status = self::STATUS_DRAFT;
        if ($statusExplicit) {
            $status = (int)$data['status'];
            if (!in_array($status, [self::STATUS_DRAFT, self::STATUS_PENDING], true)) {
                $status = self::STATUS_DRAFT;
            }
        }

        $time = time();
        $main = [
            'title' => trim((string)$data['title']),
            'version' => trim((string)($data['version'] ?? '')),
            'summary' => trim((string)($data['summary'] ?? '')),
            'publish_date' => trim((string)($data['publish_date'] ?? date('Y-m-d'))),
            'platforms' => $platforms,
            'is_important' => (int)($data['is_important'] ?? 0) ? 1 : 0,
            'is_popup' => (int)($data['is_popup'] ?? 0) ? 1 : 0,
            'sort' => (int)($data['sort'] ?? 0),
            'internal_note' => trim((string)($data['internal_note'] ?? '')),
            'publish_time' => (int)($data['publish_time'] ?? 0),
            'updated_by' => $adminId,
            'update_time' => $time,
        ];
        if ($main['is_popup'] && !$main['is_important']) {
            throw new AdminException('首页提示仅重要更新可开启');
        }
        $releaseKey = trim((string)($data['release_key'] ?? ''));
        $main['release_key'] = $releaseKey !== '' ? $releaseKey : null;

        $runner = function () use ($id, $main, $items, $adminId, $status, $statusExplicit, $time, $data) {
            $before = [];
            if ($id > 0) {
                $exist = $this->getChangelogOrFail($id);
                $exist = is_array($exist) ? $exist : $exist->toArray();
                $before = $this->buildAuditSnapshot($exist);
                if ((int)$exist['status'] === self::STATUS_PUBLISHED) {
                    // 已发布不允许通过保存改回草稿/待发布
                    unset($main['status']);
                } elseif ($statusExplicit) {
                    $main['status'] = $status;
                }
                // 未显式传 status：保留原状态（含待发布）
                if (!empty($main['release_key'])) {
                    $dup = $this->dao->getByReleaseKey((string)$main['release_key'], true);
                    if ($dup && (int)$dup['id'] !== $id) {
                        // 幂等：冲突则落到已有记录上更新，而不是报非幂等错误
                        $id = (int)$dup['id'];
                        $exist = $dup;
                        $before = $this->buildAuditSnapshot($exist);
                        if ((int)$exist['status'] === self::STATUS_PUBLISHED) {
                            unset($main['status']);
                        } elseif ($statusExplicit) {
                            $main['status'] = $status;
                        } else {
                            unset($main['status']);
                        }
                    }
                }
                $this->dao->update($id, $main);
                $this->itemDao->deleteByChangelogId($id);
                $this->saveItems($id, $items, $time);
                $afterRow = $this->getChangelogOrFail($id, true);
                $afterRow = is_array($afterRow) ? $afterRow : $afterRow->toArray();
                $afterRow['items'] = $this->itemDao->getByChangelogId($id);
                $after = $this->buildAuditSnapshot($afterRow);
                $this->auditDao->writeAudit([
                    'changelog_id' => $id,
                    'action' => (int)$exist['status'] === self::STATUS_PUBLISHED ? 'update_published' : 'update',
                    'admin_id' => $adminId,
                    'before_summary' => $before,
                    'after_summary' => $after,
                    'reason' => '',
                ]);
                return $id;
            }

            $main['status'] = $statusExplicit ? $status : self::STATUS_DRAFT;
            $main['created_by'] = $adminId;
            $main['create_time'] = $time;
            if (!empty($main['release_key'])) {
                $dup = $this->dao->getByReleaseKey((string)$main['release_key'], true);
                if ($dup) {
                    // 已存在同 release_key：幂等转为更新
                    $data['items'] = $items;
                    if (!$statusExplicit) {
                        unset($data['status']);
                    }
                    return $this->saveDraft($data, $adminId, (int)$dup['id'], false);
                }
            }
            try {
                $saved = $this->dao->save($main);
                $newId = (int)(is_object($saved) ? $saved->id : ($saved['id'] ?? 0));
                if ($newId <= 0) {
                    throw new AdminException('保存更新日志失败');
                }
            } catch (\Throwable $e) {
                if ($e instanceof AdminException) {
                    throw $e;
                }
                if (!$this->isDuplicateReleaseKeyError($e) || empty($main['release_key'])) {
                    throw $e;
                }
                $dup = $this->dao->getByReleaseKey((string)$main['release_key'], true);
                if (!$dup) {
                    throw $e;
                }
                $data['items'] = $items;
                if (!$statusExplicit) {
                    unset($data['status']);
                }
                return $this->saveDraft($data, $adminId, (int)$dup['id'], false);
            }
            $this->saveItems($newId, $items, $time);
            $afterRow = $this->getChangelogOrFail($newId, true);
            $afterRow = is_array($afterRow) ? $afterRow : $afterRow->toArray();
            $afterRow['items'] = $this->itemDao->getByChangelogId($newId);
            $after = $this->buildAuditSnapshot($afterRow);
            $this->auditDao->writeAudit([
                'changelog_id' => $newId,
                'action' => 'create',
                'admin_id' => $adminId,
                'before_summary' => '',
                'after_summary' => $after,
                'reason' => '',
            ]);
            return $newId;
        };

        return (int)($useTran ? $this->transaction($runner) : $runner());
    }

    /**
     * 发布
     * @param int $id
     * @param int $adminId
     * @param bool $useTran
     * @return bool
     */
    public function publish(int $id, int $adminId, bool $useTran = true): bool
    {
        $runner = function () use ($id, $adminId) {
            $info = $this->getChangelogOrFail($id);
            $info = is_array($info) ? $info : $info->toArray();
            if ((int)$info['status'] === self::STATUS_PUBLISHED) {
                return true;
            }
            if (!in_array((int)$info['status'], [self::STATUS_DRAFT, self::STATUS_PENDING, self::STATUS_OFFLINE], true)) {
                throw new AdminException('当前状态不可发布');
            }
            $items = $this->itemDao->getByChangelogId($id);
            $this->validateMainData($info, $items);

            $time = time();
            $publishTime = (int)($info['publish_time'] ?? 0);
            if ($publishTime <= 0) {
                $publishTime = $time;
            }

            $before = $this->buildAuditSnapshot($info);
            $update = [
                'status' => self::STATUS_PUBLISHED,
                'publish_time' => $publishTime,
                'published_by' => $adminId,
                'updated_by' => $adminId,
                'update_time' => $time,
            ];
            $this->dao->update($id, $update);
            $afterInfo = array_merge($info, $update);
            $afterInfo['items'] = $items;
            $this->auditDao->writeAudit([
                'changelog_id' => $id,
                'action' => 'publish',
                'admin_id' => $adminId,
                'before_summary' => $before,
                'after_summary' => $this->buildAuditSnapshot($afterInfo),
                'reason' => '',
            ]);
            return true;
        };

        return (bool)($useTran ? $this->transaction($runner) : $runner());
    }

    /**
     * 下架
     * @param int $id
     * @param int $adminId
     * @param string $reason
     * @return bool
     */
    public function offline(int $id, int $adminId, string $reason = ''): bool
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new AdminException('请填写下架原因');
        }
        return (bool)$this->transaction(function () use ($id, $adminId, $reason) {
            $info = $this->getChangelogOrFail($id);
            $info = is_array($info) ? $info : $info->toArray();
            if ((int)$info['status'] !== self::STATUS_PUBLISHED) {
                throw new AdminException('仅已发布日志可下架');
            }
            $before = $this->buildAuditSnapshot($info);
            $time = time();
            $update = [
                'status' => self::STATUS_OFFLINE,
                'updated_by' => $adminId,
                'update_time' => $time,
            ];
            $this->dao->update($id, $update);
            $afterInfo = array_merge($info, $update);
            $this->auditDao->writeAudit([
                'changelog_id' => $id,
                'action' => 'offline',
                'admin_id' => $adminId,
                'before_summary' => $before,
                'after_summary' => $this->buildAuditSnapshot($afterInfo),
                'reason' => $reason,
            ]);
            return true;
        });
    }

    /**
     * 删除草稿
     * @param int $id
     * @param int $adminId
     * @return bool
     */
    public function deleteDraft(int $id, int $adminId): bool
    {
        return (bool)$this->transaction(function () use ($id, $adminId) {
            $info = $this->getChangelogOrFail($id);
            $info = is_array($info) ? $info : $info->toArray();
            if ((int)$info['status'] !== self::STATUS_DRAFT) {
                throw new AdminException('仅草稿可删除');
            }
            $before = $this->buildAuditSnapshot($info);
            $this->itemDao->deleteByChangelogId($id);
            $this->dao->delete($id);
            $this->auditDao->writeAudit([
                'changelog_id' => $id,
                'action' => 'delete',
                'admin_id' => $adminId,
                'before_summary' => $before,
                'after_summary' => '',
                'reason' => '',
            ]);
            return true;
        });
    }

    /**
     * 复制为新草稿
     * @param int $id
     * @param int $adminId
     * @return int
     */
    public function copyAsDraft(int $id, int $adminId): int
    {
        $info = $this->getDetail($id, false);
        unset($info['id'], $info['audit_list']);
        $info['status'] = self::STATUS_DRAFT;
        $info['release_key'] = null;
        $info['publish_time'] = 0;
        $info['published_by'] = 0;
        $info['title'] = ($info['title'] ?? '') . '（副本）';
        return $this->saveDraft($info, $adminId, 0);
    }

    /**
     * 按 release_key 幂等写入并可选发布
     * 同一 release_key 的查找/创建/更新/发布在同一事务内，并用行锁降低并发竞态。
     * @param array $payload
     * @param int $adminId
     * @return array
     */
    public function upsertByReleaseKey(array $payload, int $adminId): array
    {
        $releaseKey = trim((string)($payload['release_key'] ?? ''));
        if ($releaseKey === '') {
            throw new AdminException('release_key 不能为空');
        }
        $autoPublish = !empty($payload['auto_publish']);
        unset($payload['auto_publish']);
        $payload['release_key'] = $releaseKey;
        $statusExplicit = array_key_exists('status', $payload);

        return $this->transaction(function () use ($payload, $adminId, $releaseKey, $autoPublish, $statusExplicit) {
            $exist = $this->dao->getByReleaseKey($releaseKey, true);
            if ($exist) {
                // 已存在：未显式传 status 时不得降级（尤其待发布→草稿）
                if (!$statusExplicit) {
                    unset($payload['status']);
                }
                $id = $this->saveDraft($payload, $adminId, (int)$exist['id'], false);
            } else {
                if (!$statusExplicit) {
                    $payload['status'] = self::STATUS_PENDING;
                }
                try {
                    $id = $this->saveDraft($payload, $adminId, 0, false);
                } catch (\Throwable $e) {
                    if (!$this->isDuplicateReleaseKeyError($e)) {
                        throw $e;
                    }
                    $exist = $this->dao->getByReleaseKey($releaseKey, true);
                    if (!$exist) {
                        throw $e;
                    }
                    if (!$statusExplicit) {
                        unset($payload['status']);
                    }
                    $id = $this->saveDraft($payload, $adminId, (int)$exist['id'], false);
                }
            }

            $published = false;
            if ($autoPublish) {
                $this->publish($id, $adminId, false);
                $published = true;
            }

            return [
                'id' => $id,
                'release_key' => $releaseKey,
                'published' => $published,
            ];
        });
    }

    /**
     * 是否 release_key 唯一约束冲突
     * @param \Throwable $e
     * @return bool
     */
    protected function isDuplicateReleaseKeyError(\Throwable $e): bool
    {
        $msg = $e->getMessage();
        if (stripos($msg, 'Duplicate') === false && stripos($msg, '1062') === false) {
            return false;
        }
        return stripos($msg, 'uk_release_key') !== false
            || stripos($msg, 'release_key') !== false;
    }

    /**
     * 公开端列表
     * @param string $platform
     * @param int $page
     * @param int $limit
     * @return array
     */
    public function getPublicList(string $platform, int $page = 0, int $limit = 0): array
    {
        $platform = $this->assertPlatform($platform);
        if (!$page || !$limit) {
            [$page, $limit] = $this->getPageValue();
        }
        $result = $this->dao->getPublishedPage($platform, $page, $limit);
        $list = $result['list'] ?? [];
        if ($list) {
            $this->attachListExtras($list, true);
        }
        return [
            'list' => $list,
            'count' => $result['count'] ?? 0,
            'change_type_map' => $this->changeTypeName,
        ];
    }

    /**
     * 公开端详情
     * @param int $id
     * @param string $platform
     * @return array
     */
    public function getPublicDetail(int $id, string $platform): array
    {
        $platform = $this->assertPlatform($platform);
        $info = $this->getChangelogOrFail($id, true);
        $info = is_array($info) ? $info : $info->toArray();
        $this->assertPublicVisible($info, $platform);
        $info['items'] = $this->itemDao->getByChangelogId($id);
        $info['platforms_arr'] = $this->platformsToArray($info['platforms'] ?? '');
        $info['type_summary'] = $this->buildTypeSummary($info['items']);
        unset($info['internal_note']);
        return $info;
    }

    /**
     * 未读信息
     * @param string $platform
     * @return array
     */
    public function getUnreadInfo(string $platform): array
    {
        $platform = $this->assertPlatform($platform);
        return [
            'latest_publish_time' => $this->dao->getLatestPublishedTime($platform),
            'server_time' => time(),
        ];
    }

    /**
     * 规范化展示端
     * @param mixed $platforms
     * @return string
     */
    public function normalizePlatforms($platforms): string
    {
        if (is_string($platforms)) {
            $platforms = array_filter(array_map('trim', explode(',', $platforms)));
        }
        if (!is_array($platforms)) {
            return '';
        }
        $valid = [];
        foreach ($platforms as $p) {
            $p = trim((string)$p);
            if (in_array($p, self::PLATFORMS, true)) {
                $valid[] = $p;
            }
        }
        $valid = array_values(array_unique($valid));
        sort($valid);
        return implode(',', $valid);
    }

    /**
     * 后台查询条件预处理
     * @param array $where
     * @return array
     */
    protected function prepareAdminWhere(array $where): array
    {
        if (!empty($where['keyword'])
            && empty($where['publish_date'])
            && empty($where['publish_date_start'])
            && empty($where['publish_date_end'])
        ) {
            $where['publish_date_min'] = date('Y-m-d', strtotime('-90 days'));
        }
        return $where;
    }

    /**
     * 列表附加明细、类型摘要、展示端数组
     * @param array $list
     * @param bool $publicMode
     */
    protected function attachListExtras(array &$list, bool $publicMode = false): void
    {
        $ids = array_column($list, 'id');
        $itemMap = $this->itemDao->getByChangelogIds($ids);
        foreach ($list as &$row) {
            $items = $itemMap[(int)$row['id']] ?? [];
            $row['items'] = $items;
            $row['type_summary'] = $this->buildTypeSummary($items);
            $row['platforms_arr'] = $this->platformsToArray($row['platforms'] ?? '');
            if ($publicMode) {
                unset($row['internal_note'], $row['release_key'], $row['created_by'], $row['updated_by'], $row['published_by']);
            } else {
                $row['status_text'] = $this->statusName[(int)($row['status'] ?? -1)] ?? '';
            }
        }
        unset($row);
    }

    /**
     * 保存明细
     * @param int $changelogId
     * @param array $items
     * @param int $time
     */
    protected function saveItems(int $changelogId, array $items, int $time): void
    {
        $rows = [];
        $sort = 0;
        foreach ($items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $content = trim((string)($item['content'] ?? ''));
            if ($content === '') {
                continue;
            }
            $changeType = trim((string)($item['change_type'] ?? 'add'));
            if (!in_array($changeType, self::CHANGE_TYPES, true)) {
                throw new AdminException('变更类型不正确');
            }
            $rows[] = [
                'changelog_id' => $changelogId,
                'change_type' => $changeType,
                'module_name' => trim((string)($item['module_name'] ?? '')),
                'content' => $content,
                'sort' => (int)($item['sort'] ?? $sort),
                'create_time' => $time,
                'update_time' => $time,
            ];
            $sort++;
        }
        if (!$rows) {
            throw new AdminException('请至少添加一条变更明细');
        }
        $this->itemDao->saveAll($rows);
    }

    /**
     * 校验主表与明细
     * @param array $data
     * @param array $items
     */
    protected function validateMainData(array $data, array $items): void
    {
        if (trim((string)($data['title'] ?? '')) === '') {
            throw new AdminException('请填写标题');
        }
        $platforms = $this->normalizePlatforms($data['platforms'] ?? '');
        if ($platforms === '') {
            throw new AdminException('请至少选择一个展示端');
        }
        $validItems = 0;
        foreach ($items as $item) {
            if (is_array($item) && trim((string)($item['content'] ?? '')) !== '') {
                $validItems++;
            }
        }
        if ($validItems < 1) {
            throw new AdminException('请至少添加一条变更明细');
        }
        $isImportant = (int)($data['is_important'] ?? 0) ? 1 : 0;
        $isPopup = (int)($data['is_popup'] ?? 0) ? 1 : 0;
        if ($isPopup && !$isImportant) {
            throw new AdminException('首页提示仅重要更新可开启');
        }
    }

    /**
     * 构建类型摘要
     * @param array $items
     * @return array
     */
    protected function buildTypeSummary(array $items): array
    {
        $summary = [];
        foreach (self::CHANGE_TYPES as $type) {
            $summary[$type] = 0;
        }
        foreach ($items as $item) {
            $type = $item['change_type'] ?? '';
            if (isset($summary[$type])) {
                $summary[$type]++;
            }
        }
        $text = [];
        foreach ($summary as $type => $count) {
            if ($count > 0) {
                $text[] = ($this->changeTypeName[$type] ?? $type) . $count . '条';
            }
        }
        return [
            'counts' => $summary,
            'text' => implode('，', $text),
        ];
    }

    /**
     * 展示端转数组
     * @param string $platforms
     * @return array
     */
    protected function platformsToArray(string $platforms): array
    {
        if ($platforms === '') {
            return [];
        }
        return array_values(array_filter(array_map('trim', explode(',', $platforms))));
    }

    /**
     * 审计快照
     * @param array $info
     * @return string
     */
    protected function buildAuditSnapshot(array $info): string
    {
        $snapshot = [
            'id' => $info['id'] ?? 0,
            'title' => $info['title'] ?? '',
            'version' => $info['version'] ?? '',
            'status' => $info['status'] ?? 0,
            'publish_date' => $info['publish_date'] ?? '',
            'platforms' => $info['platforms'] ?? '',
            'item_count' => count($info['items'] ?? []),
        ];
        return json_encode($snapshot, JSON_UNESCAPED_UNICODE);
    }

    /**
     * 获取日志或抛错
     * @param int $id
     * @param bool $apiMode
     * @return array|\think\Model
     */
    protected function getChangelogOrFail(int $id, bool $apiMode = false)
    {
        if ($id <= 0) {
            if ($apiMode) {
                throw new ApiException('日志不存在');
            }
            throw new AdminException('日志不存在');
        }
        $info = $this->dao->get($id);
        if (!$info) {
            if ($apiMode) {
                throw new ApiException('日志不存在');
            }
            throw new AdminException('日志不存在');
        }
        return $info;
    }

    /**
     * 校验公开可见
     * @param array $info
     * @param string $platform
     */
    protected function assertPublicVisible(array $info, string $platform): void
    {
        if ((int)$info['status'] !== self::STATUS_PUBLISHED) {
            throw new ApiException('日志不可见');
        }
        if ((int)($info['publish_time'] ?? 0) > time()) {
            throw new ApiException('日志尚未生效');
        }
        $platforms = $this->platformsToArray($info['platforms'] ?? '');
        if (!in_array($platform, $platforms, true)) {
            throw new ApiException('日志不可见');
        }
    }

    /**
     * 校验展示端
     * @param string $platform
     * @return string
     */
    protected function assertPlatform(string $platform): string
    {
        $platform = trim($platform);
        if (!in_array($platform, self::PLATFORMS, true)) {
            throw new ApiException('展示端参数错误');
        }
        return $platform;
    }
}
