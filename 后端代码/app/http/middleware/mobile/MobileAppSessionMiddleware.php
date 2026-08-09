<?php

declare(strict_types=1);

namespace app\http\middleware\mobile;

use app\Request;
use app\services\mobile\protocol\MobileRequestMetadata;
use mohe\interfaces\MiddlewareInterface;

final class MobileAppSessionMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        $request->mobileRequestMetadata = MobileRequestMetadata::appSession($request);
        return $next($request);
    }
}
