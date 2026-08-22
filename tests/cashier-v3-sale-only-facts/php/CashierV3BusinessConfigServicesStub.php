<?php

namespace app\services\cashier\v3\config;

/**
 * Runtime-contract stub: the production resolver reads the locked config
 * table, while this pure PHP contract deliberately has no application DB.
 */
final class CashierV3BusinessConfigServices
{
    public function resolveAccountingMethodSnapshot(string $code, bool $forUpdate = false): array
    {
        $labels = [
            'wechat' => '微信',
            'alipay' => '支付宝',
        ];
        if (!isset($labels[$code])) {
            throw new \RuntimeException('unknown test accounting method: ' . $code);
        }
        return [
            'code' => $code,
            'displayNameSnapshot' => $labels[$code],
            'configVersion' => 1,
        ];
    }
}
