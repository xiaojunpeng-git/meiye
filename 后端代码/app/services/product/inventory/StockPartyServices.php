<?php
declare(strict_types=1);

namespace app\services\product\inventory;

use app\services\store\SystemStoreServices;
use think\exception\ValidateException;

/**
 * 请货/调拨主体类型：hq=总部仓 / store=门店
 * 铁律：禁止假门店伪装总部；hq 时 store_id 必须为 0
 */
class StockPartyServices
{
    public const PARTY_HQ = 'hq';
    public const PARTY_STORE = 'store';

    public const LABEL_HQ = '总部仓';

    /**
     * @return array{party_type:string,store_id:int}
     */
    public function normalize(string $partyType, $storeId, string $roleLabel = '主体'): array
    {
        $partyType = strtolower(trim($partyType));
        if ($partyType === '') {
            $partyType = self::PARTY_STORE;
        }
        if (!in_array($partyType, [self::PARTY_HQ, self::PARTY_STORE], true)) {
            throw new ValidateException($roleLabel . '类型无效，仅支持 hq / store');
        }
        $sid = (int)$storeId;
        if ($partyType === self::PARTY_HQ) {
            if ($sid !== 0) {
                throw new ValidateException($roleLabel . '为总部仓时门店ID必须为0，禁止假门店伪装总部');
            }
            return ['party_type' => self::PARTY_HQ, 'store_id' => 0];
        }
        if ($sid <= 0) {
            throw new ValidateException('请选择' . $roleLabel . '门店');
        }
        /** @var SystemStoreServices $storeServices */
        $storeServices = app()->make(SystemStoreServices::class);
        $store = $storeServices->get($sid, ['id', 'name', 'is_show', 'is_del']);
        if (!$store || (int)$store['is_del'] === 1 || (int)$store['is_show'] !== 1) {
            throw new ValidateException($roleLabel . '门店不存在或未营业');
        }
        return ['party_type' => self::PARTY_STORE, 'store_id' => $sid];
    }

    /**
     * 库存归属：总部 type=0,rid=0；门店 type=1,rid=storeId
     * @return array{type:int,relation_id:int}
     */
    public function inventoryOwner(string $partyType, int $storeId): array
    {
        if ($partyType === self::PARTY_HQ) {
            return ['type' => 0, 'relation_id' => 0];
        }
        return ['type' => 1, 'relation_id' => $storeId];
    }

    public function label(string $partyType, int $storeId = 0, string $storeName = ''): string
    {
        if ($partyType === self::PARTY_HQ) {
            return self::LABEL_HQ;
        }
        return $storeName !== '' ? $storeName : ($storeId > 0 ? ('门店#' . $storeId) : '');
    }

    public function isHq(string $partyType): bool
    {
        return strtolower(trim($partyType)) === self::PARTY_HQ;
    }

    /**
     * 兼容旧行：无 party_type 字段时按 store_id 推断
     */
    public function partyFromRow($partyType, $storeId): string
    {
        $t = strtolower(trim((string)$partyType));
        if ($t === self::PARTY_HQ || $t === self::PARTY_STORE) {
            return $t;
        }
        return ((int)$storeId > 0) ? self::PARTY_STORE : self::PARTY_HQ;
    }
}
