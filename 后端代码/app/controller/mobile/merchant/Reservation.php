<?php

namespace app\controller\mobile\merchant;

use app\services\mobile\customer\MobileCustomerQueryServices;
use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\protocol\MobileApiResponse;
use app\services\mobile\reservation\MobileReservationServices;
use mohe\basic\BaseController;
use think\facade\App;

/** Mobile HTTP boundary for the V3 reservation lifecycle. */
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

    public function detail(int $reservationId)
    {
        return $this->read('RESERVATION_VIEW', function (array $merchant) use ($reservationId): array {
            return $this->reservations->detail($merchant, ['reservationId' => $reservationId]);
        });
    }

    public function projectCatalog()
    {
        return $this->read('RESERVATION_CREATE', function (array $merchant): array {
            return $this->reservations->projectCatalog($merchant, $this->payload());
        });
    }

    public function editor()
    {
        return $this->read('RESERVATION_CREATE', function (array $merchant): array { return $this->reservations->openEditor($merchant); });
    }

    public function memberCandidates()
    {
        return $this->read('RESERVATION_CREATE', function (array $merchant): array {
            // Keep member lookup inside the same reservation transport
            // envelope as editor/catalog commands.  The mobile client uses
            // result.status to distinguish a successful empty page from a
            // failed request; returning the provider page bare made every
            // valid member response look like an error.
            return [
                'result' => ['status' => 'success', 'code' => 'QUERY_OK', 'message' => ''],
                'data' => ['memberCandidates' => $this->customers->query($merchant, $this->payload(), false)],
            ];
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

    public function update(int $reservationId)
    {
        return $this->read('RESERVATION_CREATE', function (array $merchant) use ($reservationId): array {
            return $this->reservations->update($merchant, $reservationId, $this->payload());
        });
    }

    public function delete(int $reservationId)
    {
        return $this->read('RESERVATION_MANAGE', function (array $merchant) use ($reservationId): array {
            return $this->reservations->delete($merchant, $reservationId, $this->payload());
        });
    }

    public function startService(int $reservationId)
    {
        return $this->read('RESERVATION_MANAGE', function (array $merchant) use ($reservationId): array {
            return $this->reservations->startService($merchant, $reservationId, $this->payload());
        });
    }

    public function endService(int $reservationId)
    {
        return $this->read('RESERVATION_MANAGE', function (array $merchant) use ($reservationId): array {
            return $this->reservations->endService($merchant, $reservationId, $this->payload());
        });
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
