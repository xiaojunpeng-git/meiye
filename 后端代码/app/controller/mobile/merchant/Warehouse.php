<?php

namespace app\controller\mobile\merchant;

use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\protocol\MobileApiResponse;
use app\services\mobile\warehouse\MobileWarehouseServices;
use mohe\basic\BaseController;
use think\facade\App;

final class Warehouse extends BaseController
{
    private $merchantContext;
    private $warehouse;

    public function __construct(App $app, MobileMerchantRequestContextResolver $merchantContext, MobileWarehouseServices $warehouse)
    {
        parent::__construct($app);
        $this->merchantContext = $merchantContext;
        $this->warehouse = $warehouse;
    }

    public function overview()
    {
        $context = $this->merchantContext->resolve($this->request);
        $payload = $this->request->post();
        return MobileApiResponse::success(
            $this->warehouse->overview($context, is_array($payload) ? $payload : []),
            MobileApiResponse::MERCHANT_CONTRACT
        );
    }
}
