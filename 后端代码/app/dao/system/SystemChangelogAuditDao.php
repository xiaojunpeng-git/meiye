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
use app\model\system\SystemChangelogAudit;

/**
 * 系统更新日志审计
 * Class SystemChangelogAuditDao
 * @package app\dao\system
 */
class SystemChangelogAuditDao extends BaseDao
{
    /**
     * 设置模型
     * @return string
     */
    protected function setModel(): string
    {
        return SystemChangelogAudit::class;
    }

    /**
     * 按主日志ID获取审计记录
     * @param int $changelogId
     * @return array
     */
    public function getByChangelogId(int $changelogId): array
    {
        return $this->search(['changelog_id' => $changelogId])
            ->order('id desc')
            ->select()
            ->toArray();
    }

    /**
     * 写入审计
     * @param array $data
     * @return int
     */
    public function writeAudit(array $data): int
    {
        if (isset($data['admin_id']) && !isset($data['operator_id'])) {
            $data['operator_id'] = (int)$data['admin_id'];
            unset($data['admin_id']);
        }
        if (isset($data['before_summary']) && !is_string($data['before_summary'])) {
            $data['before_summary'] = is_array($data['before_summary'])
                ? mb_substr(json_encode($data['before_summary'], JSON_UNESCAPED_UNICODE), 0, 1000)
                : (string)$data['before_summary'];
        }
        if (isset($data['after_summary']) && !is_string($data['after_summary'])) {
            $data['after_summary'] = is_array($data['after_summary'])
                ? mb_substr(json_encode($data['after_summary'], JSON_UNESCAPED_UNICODE), 0, 1000)
                : (string)$data['after_summary'];
        }
        $data['operator_name'] = (string)($data['operator_name'] ?? '');
        $data['create_time'] = $data['create_time'] ?? time();
        $saved = $this->save($data);
        return (int)(is_object($saved) ? ($saved->id ?? 0) : ($saved['id'] ?? 0));
    }
}
