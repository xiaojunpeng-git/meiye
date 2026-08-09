<?php

namespace app\controller\mobile\merchant;

use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\protocol\MobileApiResponse;
use app\services\mobile\target\MobilePersonalMonthlyTargetServices;
use mohe\basic\BaseController;
use think\facade\App;

/** Mobile endpoint for employee-owned monthly targets. */
class PersonalMonthlyTarget extends BaseController
{
    /** @var MobileMerchantRequestContextResolver */
    private $merchantContext;
    /** @var MobilePersonalMonthlyTargetServices */
    private $targets;

    public function __construct(App $app, MobileMerchantRequestContextResolver $merchantContext, MobilePersonalMonthlyTargetServices $targets)
    {
        parent::__construct($app);
        $this->merchantContext = $merchantContext;
        $this->targets = $targets;
    }

    public function current()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'TARGET_PERSONAL_VIEW');
        return MobileApiResponse::success($this->targets->current($context), MobileApiResponse::MERCHANT_CONTRACT);
    }

    public function save()
    {
        $context = $this->merchantContext->resolve($this->request);
        $this->merchantContext->assertAction($context, 'TARGET_PERSONAL_MANAGE');
        $payload = $this->request->post();
        return MobileApiResponse::success($this->targets->save($context, is_array($payload) ? $payload : []), MobileApiResponse::MERCHANT_CONTRACT);
    }
}
