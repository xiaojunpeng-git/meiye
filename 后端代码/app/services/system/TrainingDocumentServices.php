<?php
declare(strict_types=1);

namespace app\services\system;

use think\exception\ValidateException;
use think\facade\Db;

/** 培训资料中心：资料元数据、可见范围及下载审计。 */
class TrainingDocumentServices
{
    private const TABLE = 'training_document';
    private const DOWNLOAD_TABLE = 'training_document_download';

    public function adminList(array $where): array
    {
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = min(100, max(1, (int)($where['limit'] ?? 20)));
        $query = Db::name(self::TABLE)->where('is_del', 0)
            ->when(($where['keyword'] ?? '') !== '', fn($q) => $q->whereLike('title|file_name', '%' . $where['keyword'] . '%'))
            ->when(($where['category'] ?? '') !== '', fn($q) => $q->where('category', $where['category']))
            ->when(($where['status'] ?? '') !== '', fn($q) => $q->where('status', (int)$where['status']));
        $count = (clone $query)->count();
        $list = $query->order('is_required DESC,publish_time DESC,id DESC')->page($page, $limit)->select()->toArray();
        return compact('count', 'list');
    }

    public function save(array $data, int $adminId, string $adminName): int
    {
        if (trim($data['title']) === '' || trim($data['file_name']) === '' || trim($data['file_path']) === '') {
            throw new ValidateException('资料名称和文件不能为空');
        }
        $now = time();
        $payload = [
            'title' => trim($data['title']), 'category' => trim($data['category']), 'summary' => trim($data['summary']),
            'file_name' => trim($data['file_name']), 'file_path' => trim($data['file_path']), 'file_ext' => strtolower(trim($data['file_ext'])),
            'file_size' => (int)$data['file_size'], 'version' => trim($data['version']) ?: 'V1.0',
            'client_types' => trim($data['client_types']) ?: 'all', 'role_ids' => trim($data['role_ids']), 'store_ids' => trim($data['store_ids']),
            'is_required' => (int)$data['is_required'], 'allow_download' => (int)$data['allow_download'],
            'status' => (int)$data['status'], 'update_time' => $now,
        ];
        if ($payload['status'] === 1) $payload['publish_time'] = $now;
        $id = (int)$data['id'];
        if ($id) {
            if (!Db::name(self::TABLE)->where('id', $id)->where('is_del', 0)->find()) throw new ValidateException('资料不存在');
            Db::name(self::TABLE)->where('id', $id)->update($payload);
            return $id;
        }
        $payload += ['creator_id' => $adminId, 'creator_name' => $adminName, 'add_time' => $now, 'publish_time' => $payload['status'] === 1 ? $now : 0];
        return (int)Db::name(self::TABLE)->insertGetId($payload);
    }

    public function setStatus(int $id, int $status): void
    {
        if (!in_array($status, [0, 1, 2], true)) throw new ValidateException('资料状态错误');
        $data = ['status' => $status, 'update_time' => time()];
        if ($status === 1) $data['publish_time'] = time();
        if (!Db::name(self::TABLE)->where('id', $id)->where('is_del', 0)->update($data)) throw new ValidateException('资料不存在或未变更');
    }

    public function userList(string $clientType, int $storeId, array $roles, array $where): array
    {
        $page = max(1, (int)($where['page'] ?? 1));
        $limit = min(50, max(1, (int)($where['limit'] ?? 20)));
        $query = $this->buildVisibleQuery($clientType, $storeId, $roles)
            ->when(($where['keyword'] ?? '') !== '', fn($q) => $q->whereLike('title|summary', '%' . $where['keyword'] . '%'))
            ->when(($where['category'] ?? '') !== '', fn($q) => $q->where('category', $where['category']));
        $count = (clone $query)->count();
        $list = $query->field('id,title,category,summary,file_name,file_ext,file_size,version,is_required,allow_download,publish_time,download_count')
            ->order('is_required DESC,publish_time DESC,id DESC')->page($page, $limit)->select()->toArray();
        return compact('count', 'list');
    }

    public function download(int $id, string $clientType, int $accountId, string $accountName, int $storeId, array $roles): array
    {
        $row = $this->findVisibleDocument($id, $clientType, $storeId, $roles);
        if (!$row) {
            throw new ValidateException('无权下载该资料或资料已下架');
        }
        if (!(int)($row['allow_download'] ?? 0)) {
            throw new ValidateException('该资料仅允许在线查看');
        }
        Db::transaction(function () use ($id, $clientType, $accountId, $accountName, $storeId) {
            Db::name(self::DOWNLOAD_TABLE)->insert([
                'document_id' => $id,
                'client_type' => $clientType,
                'account_id' => $accountId,
                'account_name' => $accountName,
                'store_id' => $storeId,
                'ip' => request()->ip(),
                'user_agent' => substr((string)request()->header('user-agent', ''), 0, 500),
                'add_time' => time(),
            ]);
            Db::name(self::TABLE)->where('id', $id)->inc('download_count')->update();
        });
        return [
            'file_path' => (string)($row['file_path'] ?? ''),
            'file_name' => (string)($row['file_name'] ?? ''),
        ];
    }

    /**
     * 按 id 直查一条可见资料（与列表同一套发布/端/门店/角色条件，不受分页影响）
     */
    protected function findVisibleDocument(int $id, string $clientType, int $storeId, array $roles): ?array
    {
        if ($id <= 0) {
            return null;
        }
        $row = $this->buildVisibleQuery($clientType, $storeId, $roles)
            ->where('id', $id)
            ->field('id,allow_download,file_path,file_name')
            ->find();
        return $row ? (is_array($row) ? $row : $row->toArray()) : null;
    }

    /** 用户可见资料基础查询：已发布 + 端 + 门店 + 角色 */
    protected function buildVisibleQuery(string $clientType, int $storeId, array $roles)
    {
        $query = Db::name(self::TABLE)->where(['is_del' => 0, 'status' => 1])
            ->whereRaw("(client_types = 'all' OR FIND_IN_SET(?, client_types))", [$clientType]);
        if ($storeId > 0) {
            $query->whereRaw("(store_ids = '' OR FIND_IN_SET(?, store_ids))", [$storeId]);
        }
        $roles = array_values(array_unique(array_filter(array_map('intval', $roles))));
        if ($roles) {
            $query->where(function ($q) use ($roles) {
                $q->where('role_ids', '')->whereOr(function ($roleQuery) use ($roles) {
                    foreach ($roles as $role) {
                        $roleQuery->whereOrRaw('FIND_IN_SET(?, role_ids)', [$role]);
                    }
                });
            });
        } else {
            $query->where('role_ids', '');
        }
        return $query;
    }
}
