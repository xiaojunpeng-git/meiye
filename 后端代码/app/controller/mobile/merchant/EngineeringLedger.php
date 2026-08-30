<?php

declare(strict_types=1);

namespace app\controller\mobile\merchant;

use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\protocol\MobileApiResponse;
use app\services\report\BusinessLedgerServices;
use mohe\basic\BaseController;
use think\facade\App;

/** Mobile adapter for the shared, audited engineering ledger domain. */
final class EngineeringLedger extends BaseController
{
    private $merchantContext;
    private $ledger;

    public function __construct(App $app, MobileMerchantRequestContextResolver $merchantContext, BusinessLedgerServices $ledger)
    {
        parent::__construct($app);
        $this->merchantContext = $merchantContext;
        $this->ledger = $ledger;
    }

    public function catalog()
    {
        $merchant = $this->merchant();
        return MobileApiResponse::success(['engineeringLedger' => $this->ledger->catalog()], MobileApiResponse::MERCHANT_CONTRACT);
    }

    public function list()
    {
        $merchant = $this->merchant();
        $input = $this->request->get();
        return MobileApiResponse::success(['engineeringLedger' => $this->ledger->list((string)($input['type'] ?? ''), $this->scope($merchant), is_array($input) ? $input : [])], MobileApiResponse::MERCHANT_CONTRACT);
    }

    public function save()
    {
        $merchant = $this->merchant();
        $payload = $this->request->post();
        $payload = is_array($payload) ? $payload : [];
        return MobileApiResponse::success(['engineeringLedger' => $this->ledger->save((string)($payload['type'] ?? ''), $this->scope($merchant), $payload, ['id' => (int)$merchant['operatorId'], 'name' => (string)$merchant['staffName']])], MobileApiResponse::MERCHANT_CONTRACT);
    }

    private function merchant(): array
    {
        $merchant = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($merchant, 'ENGINEERING_LEDGER_VIEW');
        return $merchant;
    }

    private function scope(array $merchant): array
    {
        return ['store_ids' => array_values(array_unique(array_map('intval', (array)$merchant['visibleStoreIds']))), 'active_store_id' => (int)$merchant['storeId']];
    }
}
