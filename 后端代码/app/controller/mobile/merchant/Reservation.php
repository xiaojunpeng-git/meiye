<?php

namespace app\controller\mobile\merchant;

use app\services\mobile\customer\MobileCustomerQueryServices;
use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\protocol\MobileApiResponse;
use app\services\mobile\reservation\MobileReservationServices;
use mohe\basic\BaseController;
use think\facade\App;

/** Mobile endpoints for the released C3 reservation-create slice only. */
class Reservation extends BaseController
{
    private $merchantContext;
    private $reservations;
    private $customers;

    public function __construct(App $app, MobileMerchantRequestContextResolver $merchantContext, MobileReservationServices $reservations, MobileCustomerQueryServices $customers)
    {
        parent::__construct($app);
        $this->merchantContext = $merchantContext;
        $this->reservations = $reservations;
        $this->customers = $customers;
    }

    public function listing()
    {
        return $this->read('RESERVATION_VIEW', function (array $merchant): array { return $this->reservations->list($merchant); });
    }

    public function editor()
    {
        return $this->read('RESERVATION_CREATE', function (array $merchant): array { return $this->reservations->openEditor($merchant); });
    }

    public function memberCandidates()
    {
        return $this->read('RESERVATION_CREATE', function (array $merchant): array {
            return ['memberCandidates' => $this->customers->query($merchant, $this->payload(), false)];
        });
    }

    public function recalculate()
    {
        return $this->read('RESERVATION_CREATE', function (array $merchant): array { return $this->reservations->recalculate($merchant, $this->payload()); });
    }

    public function create()
    {
        return $this->read('RESERVATION_CREATE', function (array $merchant): array { return $this->reservations->create($merchant, $this->payload()); });
    }

    private function read(string $requiredAction, callable $callback)
    {
        $merchant = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($merchant, $requiredAction);
        return MobileApiResponse::success(['reservation' => $callback($merchant)], MobileApiResponse::MERCHANT_CONTRACT);
    }

    private function payload(): array
    {
        $payload = $this->request->post();
        return is_array($payload) ? $payload : [];
    }
}
