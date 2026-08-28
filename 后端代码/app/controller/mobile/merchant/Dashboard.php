<?php

declare(strict_types=1);

namespace app\controller\mobile\merchant;

use app\services\mobile\dashboard\MobileMerchantDashboardServices;
use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\protocol\MobileApiResponse;
use mohe\basic\BaseController;
use think\facade\App;

final class Dashboard extends BaseController
{
    private $merchantContext;
    private $dashboard;

    public function __construct(App $app, MobileMerchantRequestContextResolver $merchantContext, MobileMerchantDashboardServices $dashboard)
    {
        parent::__construct($app);
        $this->merchantContext = $merchantContext;
        $this->dashboard = $dashboard;
    }

    public function home()
    {
        $context = $this->merchantContext->resolve($this->request);
        $payload = $this->request->get();
        if (!is_array($payload) || $payload === []) {
            $payload = $this->request->post();
        }

        return MobileApiResponse::success(
            $this->dashboard->home($context, is_array($payload) ? $payload : []),
            MobileApiResponse::MERCHANT_CONTRACT
        );
    }
}
