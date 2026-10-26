<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\store\SystemStoreServices;
use think\exception\ValidateException;
use think\facade\Db;

/**
 * 平台库存监管范围：总部仓 / 指定门店 / 全部门店汇总
 * P1：hq|store|all；all 仅 type=1 且有效门店 store_id>0，绝不含总部仓
 */
class InventoryScopeServices
{
    public const SCOPE_HQ = 'hq';
    public const SCOPE_STORE = 'store';
    public const SCOPE_ALL = 'all';

    /**
     * @return array{scope:string,type:int,relation_id:int|string,all_stores:bool,split_display:int,scope_label:string,store_id:int}
     */
    public function resolve(string $scope, $storeId = ''): array
    {
        $scope = strtolower(trim($scope));
        if ($scope === '') {
            $scope = self::SCOPE_HQ;
        }
        if (!in_array($scope, [self::SCOPE_HQ, self::SCOPE_STORE, self::SCOPE_ALL], true)) {
            throw new ValidateException('监管范围无效，仅支持 hq / store / all');
        }

        if ($scope === self::SCOPE_HQ) {
            return [
                'scope' => self::SCOPE_HQ,
                'type' => 0,
                'relation_id' => 0,
                'all_stores' => false,
                'split_display' => 0,
                'scope_label' => '总部仓',
                'store_id' => 0,
            ];
        }

        if ($scope === self::SCOPE_ALL) {
            return [
                'scope' => self::SCOPE_ALL,
                'type' => 1,
                'relation_id' => '',
                'all_stores' => true,
                'split_display' => 1,
                'scope_label' => '全部门店汇总',
                'store_id' => 0,
            ];
        }

        $sid = (int)$storeId;
        if ($sid <= 0) {
            throw new ValidateException('指定门店监管时请选择门店');
        }
        $this->assertValidStore($sid);
        return [
            'scope' => self::SCOPE_STORE,
            'type' => 1,
            'relation_id' => $sid,
            'all_stores' => false,
            'split_display' => 1,
            'scope_label' => '指定门店',
            'store_id' => $sid,
        ];
    }

    /**
     * 从请求参数解析（强制校验）
     */
    public function resolveFromRequest(array $params): array
    {
        return $this->resolve((string)($params['scope'] ?? 'hq'), $params['store_id'] ?? '');
    }

    /**
     * 写入类接口：仅允许总部仓；监管范围必须拒绝（禁止静默写总部）
     */
    public function assertHqWriteOnly(array $params): void
    {
        $scope = strtolower(trim((string)($params['scope'] ?? 'hq')));
        if ($scope === '') {
            $scope = self::SCOPE_HQ;
        }
        if ($scope !== self::SCOPE_HQ) {
            throw new ValidateException('新建/导入/保存仅支持总部仓，监管范围（指定门店/全部门店汇总）禁止写入');
        }
        // 即使误传 store_id 也不允许在监管语义下写入
        if (isset($params['store_id']) && $params['store_id'] !== '' && (int)$params['store_id'] > 0) {
            throw new ValidateException('新建/导入/保存仅支持总部仓，请勿传门店 store_id');
        }
    }

    /**
     * 真实有效门店：存在、未删除、营业中
     */
    public function assertValidStore(int $storeId): void
    {
        if ($storeId <= 0) {
            throw new ValidateException('门店无效');
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $store = $storeServices->get($storeId, ['id', 'name', 'is_show', 'is_del']);
        if (!$store || (int)$store['is_del'] === 1 || (int)$store['is_show'] !== 1) {
            throw new ValidateException('门店不存在或未营业');
        }
    }

    /**
     * 有效门店 ID 列表（缓存单次请求内可重复用）
     * @return int[]
     */
    public function validStoreIds(): array
    {
        return array_map('intval', Db::name('system_store')
            ->where('is_del', 0)
            ->where('is_show', 1)
            ->column('id'));
    }

    /**
     * 写入列表/统计 where（配合搜索器：all 时不写 relation_id）
     */
    public function applyOwnerWhere(array &$where, array $resolved): void
    {
        $where['type'] = $resolved['type'];
        if (!empty($resolved['all_stores'])) {
            unset($where['relation_id']);
            $where['all_stores'] = 1;
        } else {
            $where['relation_id'] = $resolved['relation_id'];
            unset($where['all_stores']);
        }
    }
}
