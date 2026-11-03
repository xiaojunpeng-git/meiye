<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\config\CashierV3BusinessConfigServices;
use app\services\cashier\v3\config\CashierV3BusinessSourcePolicyServices;

final class CashierV3CheckoutBusinessConfigProjectionServices
{
    /** @var CashierV3CheckoutBusinessSourceSelectionServices */
    private $businessSources;

    /** @var CashierV3BusinessConfigServices */
    private $businessConfig;

    public function __construct(
        CashierV3CheckoutBusinessSourceSelectionServices $businessSources = null,
        CashierV3BusinessConfigServices $businessConfig = null
    ) {
        $this->businessSources = $businessSources ?: new CashierV3CheckoutBusinessSourceSelectionServices();
        $this->businessConfig = $businessConfig ?: new CashierV3BusinessConfigServices();
    }

    public function apply(array $projection, string $kind, array $request): array
    {
        $policy = new CashierV3BusinessSourcePolicyServices();
        $settings = $policy->read();
        $selection = $this->businessSources->read(
            $kind,
            (string)($request['request_id'] ?? ''),
            (string)($request['tenant_id'] ?? ''),
            (int)($request['store_id'] ?? 0)
        );
        if ($kind === CashierV3CheckoutBusinessSourceSelectionServices::KIND_SALE
            && (int)($selection['primarySourceId'] ?? 0) <= 0) {
            // Only a new choice uses policy defaults; a captured checkout keeps
            // its own selection across projection refreshes and failed retries.
            try {
                $selection = $policy->initialChoice(
                    'purchase',
                    (string)($request['tenant_id'] ?? ''),
                    (int)($request['store_id'] ?? 0),
                    (int)($request['member_id'] ?? 0)
                );
            } catch (\think\exception\ValidateException $error) {
                // Bad fixed settings must block a new checkout, not login or
                // historical viewing. Surface the same configuration failure.
                $projection['sourceInvalidMessage'] = $error->getMessage();
            }
        }
        $projection['sourceEnabled'] = $settings['enabled'];
        $projection['sourceSelectable'] = $settings['enabled'] && (string)($projection['businessType'] ?? '') !== 'debt_repayment';
        $projection['businessSourcePolicy'] = $settings;
        $projection['sourceLabel'] = (string)($selection['displayNameSnapshot'] ?? '');
        if ($kind !== CashierV3CheckoutBusinessSourceSelectionServices::KIND_SALE) {
            $projection['sourceSelectionVersion'] = (int)($selection['selectionVersion'] ?? 0);
        }
        $projection['primarySourceId'] = (int)($selection['primarySourceId'] ?? 0);
        $projection['secondarySourceId'] = (int)($selection['secondarySourceId'] ?? 0);
        $projection['rewardAmountCents'] = (int)($selection['rewardAmountCents'] ?? 0);

        return $this->applyAccountingMethodNames($projection);
    }

    private function applyAccountingMethodNames(array $projection): array
    {
        $names = [];
        foreach ($this->businessConfig->accountingMethods(false) as $index => $method) {
            $names[(string)$method['code']] = [
                'name' => (string)$method['displayName'],
                'enabled' => (int)$method['status'] === 1,
                'sort' => (int)$method['sort'],
                'index' => $index,
            ];
        }
        $payment = (array)($projection['payment'] ?? []);
        $payment['methods'] = array_values(array_filter(array_map(static function (array $method) use ($names): ?array {
            $configured = $names[(string)($method['id'] ?? '')] ?? null;
            if ($configured === null || !$configured['enabled']) return null;
            $method['name'] = $configured['name'];
            return $method;
        }, (array)($payment['methods'] ?? []))));
        // 服务端投影与本地预览均按记账配置排序，两个结账入口不能显示不同顺序。
        usort($payment['methods'], static function (array $a, array $b) use ($names): int {
            return ($names[$a['id']]['sort'] <=> $names[$b['id']]['sort'])
                ?: ($names[$a['id']]['index'] <=> $names[$b['id']]['index']);
        });
        foreach ((array)($payment['selectedLines'] ?? []) as $index => $line) {
            $configured = $names[(string)($line['method'] ?? '')] ?? null;
            if ($configured !== null) $payment['selectedLines'][$index]['name'] = $configured['name'];
        }
        $projection['payment'] = $payment;

        return $projection;
    }
}
