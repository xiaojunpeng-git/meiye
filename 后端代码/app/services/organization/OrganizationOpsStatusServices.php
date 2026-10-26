<?php
namespace app\services\organization;

use app\services\BaseServices;
use mohe\exceptions\AdminException;
use think\facade\Db;

/**
 * I2 组织自身停用 / 门店营业停用
 */
class OrganizationOpsStatusServices extends BaseServices
{
    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function setOrganizationStatus(
        int $orgId,
        int $status,
        string $reason,
        array $adminInfo,
        array $requestCtx
    ): array {
        $status = $status === 1 ? 1 : 0;
        $payload = [
            'org_id' => $orgId,
            'status' => $status,
            'reason' => $reason,
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'organization_ops_status',
            'organization:' . $orgId . ':ops_status',
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($orgId, $status, $reason) {
                if ($orgId <= 0) {
                    throw new AdminException('请选择组织');
                }
                $org = Db::name('organization')->where('id', $orgId)->where('is_del', 0)->lock(true)->find();
                if (!$org) {
                    throw new AdminException('组织不存在或已删除');
                }
                $reason = trim($reason);
                if ($reason === '') {
                    throw new AdminException('请填写停用或恢复原因');
                }
                Db::name('organization')->where('id', $orgId)->update([
                    'status' => $status,
                    'status_reason' => mb_substr($reason, 0, 255),
                ]);

                $impactStores = $this->countImpactStoresUnderOrg($orgId);
                $msg = $status === 1 ? '组织已恢复' : '组织已停用';
                return [
                    'msg' => $msg,
                    'data' => [
                        'org_id' => $orgId,
                        'status' => $status,
                        'status_reason' => $reason,
                        'impact_store_count' => $impactStores,
                        'effectively_enabled' => $this->isOrganizationEffectivelyEnabled($orgId),
                        'help' => $status === 1
                            ? '恢复上级后，原本单独停用的下级组织和门店仍保持停用，不会一并自动恢复。'
                            : '停用后，下级组织与所属门店将继承停用（业务入口立即不可用）；不会批量改写下级自身状态。',
                    ],
                ];
            }
        );
    }

    /**
     * @return array{msg:string,data:array,replay:bool}
     */
    public function setStoreBusinessStatus(
        int $storeId,
        int $status,
        string $reason,
        array $adminInfo,
        array $requestCtx
    ): array {
        $status = $status === 1 ? 1 : 0;
        $payload = [
            'store_id' => $storeId,
            'status' => $status,
            'reason' => $reason,
        ];
        /** @var OrganizationStrictIdempotencyServices $idem */
        $idem = app()->make(OrganizationStrictIdempotencyServices::class);
        return $idem->run(
            'store_ops_status',
            'store:' . $storeId . ':ops_status',
            $payload,
            $adminInfo,
            $requestCtx,
            function (array $auditMeta) use ($storeId, $status, $reason) {
                if ($storeId <= 0) {
                    throw new AdminException('请选择门店');
                }
                $store = Db::name('system_store')->where('id', $storeId)->where('is_del', 0)->lock(true)->find();
                if (!$store) {
                    throw new AdminException('门店不存在或已删除');
                }
                $reason = trim($reason);
                if ($reason === '') {
                    throw new AdminException('请填写停用或恢复原因');
                }
                Db::name('system_store')->where('id', $storeId)->update([
                    'business_status' => $status,
                    'business_status_reason' => mb_substr($reason, 0, 255),
                ]);
                $msg = $status === 1 ? '门店已恢复营业' : '门店已停用';
                $enabled = $this->isStoreBusinessEnabled($storeId);
                return [
                    'msg' => $msg,
                    'data' => [
                        'store_id' => $storeId,
                        'business_status' => $status,
                        'business_status_reason' => $reason,
                        'effectively_enabled' => $enabled,
                        'help' => $status === 1
                            ? '门店恢复营业后，原任职与授权按各自有效状态继续生效；若所属组织仍停用，业务仍不可用。'
                            : '停用后门店后台、收银台、手机端业务入口立即不可用，禁止新增业务写入；历史数据保留，总部仍可查询并恢复。',
                    ],
                ];
            }
        );
    }

    public function isOrganizationEffectivelyEnabled(int $orgId): bool
    {
        if ($orgId <= 0) {
            return false;
        }
        $rows = Db::name('organization')->field('id,pid,status,is_del')->select()->toArray();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r['id']] = $r;
        }
        $cur = $orgId;
        $guard = 0;
        while ($cur > 0 && $guard < 64) {
            if (!isset($map[$cur])) {
                return false;
            }
            $node = $map[$cur];
            if ((int)($node['is_del'] ?? 0) === 1) {
                return false;
            }
            if ((int)($node['status'] ?? 1) === 0) {
                return false;
            }
            $cur = (int)($node['pid'] ?? 0);
            $guard++;
        }
        return true;
    }

    public function isStoreBusinessEnabled(int $storeId): bool
    {
        if ($storeId <= 0) {
            return false;
        }
        $store = Db::name('system_store')->where('id', $storeId)->find();
        if (!$store || (int)($store['is_del'] ?? 0) === 1) {
            return false;
        }
        if ((int)($store['business_status'] ?? 1) !== 1) {
            return false;
        }
        $orgId = (int)Db::name('organization_store')->where('store_id', $storeId)->value('org_id');
        if ($orgId > 0 && !$this->isOrganizationEffectivelyEnabled($orgId)) {
            return false;
        }
        return true;
    }

    public function assertStoreBusinessWritable(int $storeId): void
    {
        if (!$this->isStoreBusinessEnabled($storeId)) {
            throw new AdminException('门店已停用或所属组织已停用，暂不可办理业务');
        }
    }

    protected function countImpactStoresUnderOrg(int $orgId): int
    {
        $rows = Db::name('organization')->where('is_del', 0)->field('id,pid')->select()->toArray();
        $children = [];
        foreach ($rows as $r) {
            $children[(int)($r['pid'] ?? 0)][] = (int)$r['id'];
        }
        $orgIds = [];
        $stack = [$orgId];
        $guard = 0;
        while ($stack && $guard < 5000) {
            $cur = (int)array_pop($stack);
            if ($cur <= 0 || isset($orgIds[$cur])) {
                $guard++;
                continue;
            }
            $orgIds[$cur] = true;
            foreach ($children[$cur] ?? [] as $cid) {
                $stack[] = (int)$cid;
            }
            $guard++;
        }
        if (!$orgIds) {
            return 0;
        }
        return (int)Db::name('organization_store')->alias('os')
            ->join('system_store st', 'st.id = os.store_id')
            ->whereIn('os.org_id', array_keys($orgIds))
            ->where('st.is_del', 0)
            ->count();
    }
}
