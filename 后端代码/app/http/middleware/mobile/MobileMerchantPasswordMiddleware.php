<?php

declare(strict_types=1);

namespace app\http\middleware\mobile;

use app\Request;
use app\services\mobile\protocol\MobileRequestMetadata;
use mohe\interfaces\MiddlewareInterface;

final class MobileMerchantPasswordMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        $request->mobileRequestMetadata = MobileRequestMetadata::employeePassword($request);
        return $next($request);
    }
}
