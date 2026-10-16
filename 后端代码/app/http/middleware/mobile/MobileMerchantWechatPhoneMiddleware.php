<?php

declare(strict_types=1);

namespace app\http\middleware\mobile;

use app\Request;
use app\services\mobile\protocol\MobileRequestMetadata;
use mohe\interfaces\MiddlewareInterface;

/** A public merchant-login envelope that never accepts an existing token. */
final class MobileMerchantWechatPhoneMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        $request->mobileRequestMetadata = MobileRequestMetadata::wechatPhone($request);
        return $next($request);
    }
}
