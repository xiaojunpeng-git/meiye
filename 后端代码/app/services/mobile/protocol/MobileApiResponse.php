<?php

declare(strict_types=1);

namespace app\services\mobile\protocol;

use think\response\Json;

final class MobileApiResponse
{
    public const AUTH_CONTRACT = 'mobile-auth-v1';
    public const MERCHANT_CONTRACT = 'mobile-merchant-v1';

    public static function success(array $payload, string $contractVersion): Json
    {
        return json(array_merge(['contractVersion' => $contractVersion], $payload));
    }

    public static function protocolFailure(MobileApiException $exception, string $requestId, int $status = 400): Json
    {
        return json([
            'contractVersion' => self::AUTH_CONTRACT,
            'requestId' => $requestId,
            'protocolCode' => $exception->mobileCode(),
            'fieldPath' => $exception->fieldPath(),
            'message' => $exception->getMessage(),
        ], $status);
    }

    /**
     * The route pipeline hands thrown failures directly to the global exception
     * handler, so both entry points must delegate to this one renderer.
     */
    public static function failure(MobileApiException $exception, $request): Json
    {
        $requestId = trim((string)$request->header('X-Mobile-Request-Id', ''));
        if ($requestId === '') {
            $requestId = 'unavailable';
        }
        if ($exception->kind() === 'protocol') {
            return self::protocolFailure($exception, $requestId);
        }
        return self::businessFailure($exception, $requestId, self::contractFor($request));
    }

    public static function validationFailure(\Throwable $exception, $request): Json
    {
        return self::failure(
            MobileApiException::protocol('INVALID_REQUEST_FIELD', $exception->getMessage()),
            $request
        );
    }

    public static function businessFailure(MobileApiException $exception, string $requestId, string $contractVersion): Json
    {
        $payload = [
            'contractVersion' => $contractVersion,
            'requestId' => $requestId,
            'errorCode' => $exception->mobileCode(),
            'message' => $exception->getMessage(),
        ];
        if ($exception->mobileCode() === 'MERCHANT_SESSION_EXPIRED' && $exception->sessionEndCause() !== null) {
            $payload['sessionEndCause'] = $exception->sessionEndCause();
        }
        return json($payload, 401);
    }

    private static function contractFor($request): string
    {
        return strpos((string)$request->pathinfo(), 'api/mobile/auth/') !== false
            ? self::AUTH_CONTRACT
            : self::MERCHANT_CONTRACT;
    }
}
