<?php
// +----------------------------------------------------------------------
// | MOHE [ MOHE赋能开发者，助力企业发展 ]
// +----------------------------------------------------------------------
// | Copyright (c) 2016~2022 https://www.mohe.com All rights reserved.
// +----------------------------------------------------------------------
// | Licensed MOHE并不是自由软件，未经许可不能去掉MOHE相关版权
// +----------------------------------------------------------------------
// | Author: MOHE Team <admin@mohe.com>
// +----------------------------------------------------------------------

namespace app\services\other;

use app\dao\other\CacheDao;
use app\dao\other\StoreGiftConfigDao;
use app\model\other\StoreGiftConfig;
use app\services\BaseServices;

/**
 * 赠送配置（商品/储值档位等）
 */
class StoreGiftConfigServices extends BaseServices
{
    public const GIFT_TYPE_PRODUCT = StoreGiftConfig::GIFT_TYPE_PRODUCT;

    public const GIFT_TYPE_RECHARGE = StoreGiftConfig::GIFT_TYPE_RECHARGE;

    public function __construct(StoreGiftConfigDao $dao)
    {
        $this->dao = $dao;
    }

    public function normalizeSendAll($sendAll): array
    {
        if (!is_array($sendAll)) {
            $sendAll = [];
        }
        return [
            'product' => isset($sendAll['product']) && is_array($sendAll['product']) ? $sendAll['product'] : [],
            'coupon' => isset($sendAll['coupon']) && is_array($sendAll['coupon']) ? $sendAll['coupon'] : [],
        ];
    }

    protected function decodeConfig($config): array
    {
        if (is_array($config)) {
            return $this->normalizeSendAll($config);
        }
        if (!is_string($config) || $config === '') {
            return ['product' => [], 'coupon' => []];
        }
        $data = json_decode($config, true);
        return $this->normalizeSendAll(is_array($data) ? $data : []);
    }

    public function getConfig(int $giftType, int $relationId): array
    {
        if (!$giftType || !$relationId) {
            return ['product' => [], 'coupon' => []];
        }
        $row = $this->dao->getOne([
            'gift_type' => $giftType,
            'relation_id' => $relationId,
        ], 'config');
        if ($row && isset($row['config']) && $row['config'] !== '') {
            return $this->decodeConfig($row['config']);
        }
        return $this->getLegacyCacheConfig($giftType, $relationId);
    }

    protected function getLegacyCacheKey(int $giftType, int $relationId): ?string
    {
        if ($giftType === self::GIFT_TYPE_PRODUCT) {
            return 'product_gift_config_' . $relationId;
        }
        if ($giftType === self::GIFT_TYPE_RECHARGE) {
            return 'recharge_gift_config_' . $relationId;
        }
        return null;
    }

    protected function getLegacyCacheConfig(int $giftType, int $relationId): array
    {
        $cacheKey = $this->getLegacyCacheKey($giftType, $relationId);
        if (!$cacheKey) {
            return ['product' => [], 'coupon' => []];
        }
        /** @var CacheDao $cacheDao */
        $cacheDao = app()->make(CacheDao::class);
        $result = $cacheDao->value(['key' => $cacheKey], 'result');
        if (!$result) {
            return ['product' => [], 'coupon' => []];
        }
        $config = $this->decodeConfig($result);
        if ($config['product'] || $config['coupon']) {
            $this->saveConfig($giftType, $relationId, $config);
        }
        return $config;
    }

    public function saveConfig(int $giftType, int $relationId, array $sendAll): void
    {
        $config = $this->normalizeSendAll($sendAll);
        $now = time();
        $payload = [
            'config' => json_encode($config, JSON_UNESCAPED_UNICODE),
            'update_time' => $now,
        ];
        $exist = $this->dao->getOne([
            'gift_type' => $giftType,
            'relation_id' => $relationId,
        ], 'id');
        if ($exist) {
            $this->dao->update($exist['id'], $payload);
            return;
        }
        $this->dao->save(array_merge($payload, [
            'gift_type' => $giftType,
            'relation_id' => $relationId,
            'add_time' => $now,
        ]));
    }

    /**
     * 批量获取配置，返回 relation_id => config 数组
     */
    public function getConfigMap(int $giftType, array $relationIds): array
    {
        if (!$giftType || !$relationIds) {
            return [];
        }
        $relationIds = array_values(array_unique(array_filter(array_map('intval', $relationIds))));
        if (!$relationIds) {
            return [];
        }
        $configRows = $this->dao->getConfigMap($giftType, $relationIds);
        $normalizedRows = [];
        foreach ($configRows as $relationId => $config) {
            $normalizedRows[(int)$relationId] = $config;
        }
        $configMap = [];
        foreach ($relationIds as $relationId) {
            $configMap[$relationId] = isset($normalizedRows[$relationId])
                ? $this->decodeConfig($normalizedRows[$relationId])
                : ['product' => [], 'coupon' => []];
        }
        return $configMap;
    }
}
