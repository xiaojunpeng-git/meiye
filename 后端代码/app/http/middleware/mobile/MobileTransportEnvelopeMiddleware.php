<?php

declare(strict_types=1);

namespace app\http\middleware\mobile;

use app\Request;
use app\services\mobile\protocol\MobileApiException;
use app\services\mobile\protocol\MobileApiResponse;
use mohe\interfaces\MiddlewareInterface;
use think\exception\ValidateException;

final class MobileTransportEnvelopeMiddleware implements MiddlewareInterface
{
    public function handle(Request $request, \Closure $next)
    {
        try {
            return $next($request);
        } catch (MobileApiException $exception) {
            return MobileApiResponse::failure($exception, $request);
        } catch (ValidateException $exception) {
            return MobileApiResponse::validationFailure($exception, $request);
        }
    }
}
