<?php

declare(strict_types=1);

namespace app\controller\mobile\merchant;

use app\services\mobile\merchant\MobileMerchantProfileServices;
use app\services\mobile\merchant\MobileMerchantRequestContextResolver;
use app\services\mobile\protocol\MobileApiResponse;
use mohe\basic\BaseController;
use think\facade\App;

/** Mobile merchant account and data-scope read/write boundary. */
final class Profile extends BaseController
{
    private MobileMerchantRequestContextResolver $merchantContext;
    private MobileMerchantProfileServices $profiles;

    public function __construct(App $app, MobileMerchantRequestContextResolver $merchantContext, MobileMerchantProfileServices $profiles)
    {
        parent::__construct($app);
        $this->merchantContext = $merchantContext;
        $this->profiles = $profiles;
    }

    public function account()
    {
        $context = $this->merchantContext->resolve($this->request);
        return MobileApiResponse::success($this->profiles->account($context), MobileApiResponse::MERCHANT_CONTRACT);
    }

    public function scope()
    {
        $context = $this->merchantContext->resolve($this->request);
        return MobileApiResponse::success($this->profiles->scope($context), MobileApiResponse::MERCHANT_CONTRACT);
    }

    public function credentials()
    {
        $context = $this->merchantContext->resolve($this->request);
        $payload = $this->request->post();
        return MobileApiResponse::success(
            $this->profiles->changeCredentials($context, is_array($payload) ? $payload : []),
            MobileApiResponse::MERCHANT_CONTRACT
        );
    }
}
