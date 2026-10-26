<?php

namespace app\services\cashier\v3\settlement;

use app\services\cashier\v3\config\CashierV3BusinessConfigServices;

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
        $selection = $this->businessSources->read(
            $kind,
            (string)($request['request_id'] ?? ''),
            (string)($request['tenant_id'] ?? ''),
            (int)($request['store_id'] ?? 0)
        );
        if ($kind === CashierV3CheckoutBusinessSourceSelectionServices::KIND_SALE
            && (int)($selection['primarySourceId'] ?? 0) <= 0) {
            $selection = $this->businessSources->latestNormalMemberSource(
                (string)($request['tenant_id'] ?? ''),
                (int)($request['store_id'] ?? 0),
                (int)($request['member_id'] ?? 0)
            );
        }
        $projection['sourceEnabled'] = true;
        $projection['sourceSelectable'] = (string)($projection['businessType'] ?? '') !== 'debt_repayment';
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
        foreach ($this->businessConfig->accountingMethods(false) as $method) {
            $names[(string)$method['code']] = [
                'name' => (string)$method['displayName'],
                'enabled' => (int)$method['status'] === 1,
            ];
        }
        $payment = (array)($projection['payment'] ?? []);
        $payment['methods'] = array_values(array_filter(array_map(static function (array $method) use ($names): ?array {
            $configured = $names[(string)($method['id'] ?? '')] ?? null;
            if ($configured === null || !$configured['enabled']) return null;
            $method['name'] = $configured['name'];
            return $method;
        }, (array)($payment['methods'] ?? []))));
        foreach ((array)($payment['selectedLines'] ?? []) as $index => $line) {
            $configured = $names[(string)($line['method'] ?? '')] ?? null;
            if ($configured !== null) $payment['selectedLines'][$index]['name'] = $configured['name'];
        }
        $projection['payment'] = $payment;

        return $projection;
    }
}
