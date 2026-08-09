<?php

declare(strict_types=1);

namespace app\services\mobile\protocol;

final class MobileRequestMetadata
{
    private const COMMON_HEADERS = [
        'X-Mobile-Contract-Version' => 'contractVersion',
        'X-Mobile-Client-Session-Id' => 'clientSessionId',
        'X-Mobile-Platform' => 'platform',
        'X-Mobile-Request-Id' => 'requestId',
    ];

    public static function public($request): array
    {
        return self::resolve($request, 'PUBLIC', MobileApiResponse::AUTH_CONTRACT);
    }

    public static function appSession($request): array
    {
        return self::resolve($request, 'APP_SESSION_EXCHANGE', MobileApiResponse::MERCHANT_CONTRACT);
    }

    public static function employeePassword($request): array
    {
        return self::resolve($request, 'PUBLIC', MobileApiResponse::MERCHANT_CONTRACT);
    }

    public static function merchant($request): array
    {
        return self::resolve($request, 'MERCHANT_SESSION', MobileApiResponse::MERCHANT_CONTRACT);
    }

    private static function resolve($request, string $profile, string $expectedContract): array
    {
        if (strtoupper((string)$request->method()) !== 'GET' && !self::isJson($request)) {
            throw MobileApiException::protocol('UNSUPPORTED_MEDIA_TYPE', '移动接口只接受 application/json 请求。', 'Content-Type');
        }
        $headers = [];
        foreach (self::COMMON_HEADERS as $header => $key) {
            $value = trim((string)$request->header($header, ''));
            if ($value === '') {
                throw MobileApiException::protocol('CONTRACT_HEADER_INVALID', '缺少移动接口请求头。', $header);
            }
            $headers[$key] = $value;
        }
        if ($headers['contractVersion'] !== $expectedContract) {
            throw MobileApiException::business('CONTRACT_VERSION_UNSUPPORTED', '手机端接口版本不匹配，请升级后重试。');
        }
        foreach (['clientSessionId', 'requestId'] as $opaque) {
            if (!preg_match('/^[A-Za-z0-9._:-]{8,64}$/', $headers[$opaque])) {
                throw MobileApiException::protocol('CONTRACT_HEADER_INVALID', '移动接口标识格式无效。', $opaque === 'clientSessionId' ? 'X-Mobile-Client-Session-Id' : 'X-Mobile-Request-Id');
            }
        }
        if (!preg_match('/^[A-Za-z0-9._-]{2,32}$/', $headers['platform'])) {
            throw MobileApiException::protocol('CONTRACT_HEADER_INVALID', '移动平台标识格式无效。', 'X-Mobile-Platform');
        }
        self::assertNoTokenInBodyOrQuery($request);
        self::assertNoCookie($request);

        $authorization = trim((string)$request->header('Authorization', ''));
        $legacyAuthorization = trim((string)$request->header('Authori-zation', ''));
        $appSession = trim((string)$request->header('X-Mobile-App-Session', ''));
        $activeContext = trim((string)$request->header('X-Mobile-Active-Context-Id', ''));
        $stateContext = trim((string)$request->header('X-Mobile-State-Context-Id', ''));
        if ($profile === 'PUBLIC') {
            if ($authorization !== '' || $legacyAuthorization !== '' || $appSession !== '' || $activeContext !== '' || $stateContext !== '') {
                throw MobileApiException::protocol('CREDENTIAL_FORBIDDEN', '公开移动接口不能携带会话凭据。', 'headers');
            }
            return $headers;
        }
        if ($profile === 'APP_SESSION_EXCHANGE') {
            if ($authorization !== '' || $legacyAuthorization !== '' || $activeContext !== '' || $stateContext !== '') {
                throw MobileApiException::protocol('CREDENTIAL_CONFLICT', 'App 会话交换请求混入了商家会话凭据。', 'headers');
            }
            if (!self::opaque($appSession)) {
                throw MobileApiException::business('AUTH_REQUIRED', '请先完成手机号验证。');
            }
            $headers['appSession'] = $appSession;
            return $headers;
        }
        if ($appSession !== '') {
            throw MobileApiException::protocol('CREDENTIAL_CONFLICT', '商家会话请求不能同时携带 App 会话凭据。', 'headers');
        }
        if ($legacyAuthorization !== '') {
            throw MobileApiException::protocol('CREDENTIAL_FORBIDDEN', '不支持兼容授权请求头。', 'Authori-zation');
        }
        if (!preg_match('/^Bearer ([A-Za-z0-9._~-]{24,256})$/', $authorization, $matches)) {
            throw MobileApiException::business('AUTH_REQUIRED', '请重新进入商家端。');
        }
        if (!self::opaque($activeContext) || !self::opaque($stateContext)) {
            throw MobileApiException::protocol('CONTRACT_HEADER_INVALID', '商家上下文请求头无效。', 'X-Mobile-Active-Context-Id');
        }
        $headers['merchantToken'] = $matches[1];
        $headers['activeContextId'] = $activeContext;
        $headers['stateContextId'] = $stateContext;
        return $headers;
    }

    private static function assertNoTokenInBodyOrQuery($request): void
    {
        $blocked = ['token', 'accessToken', 'merchantToken', 'appSession', 'authorization'];
        foreach ($blocked as $key) {
            if ($request->get($key, null) !== null || $request->post($key, null) !== null) {
                throw MobileApiException::protocol('CREDENTIAL_FORBIDDEN', '凭据只能放在合同指定请求头。', $key);
            }
        }
    }

    private static function assertNoCookie($request): void
    {
        if (trim((string)$request->header('Cookie', '')) !== '') {
            throw MobileApiException::protocol('CREDENTIAL_FORBIDDEN', '移动接口不接受 Cookie 会话。', 'Cookie');
        }
    }

    private static function opaque(string $value): bool
    {
        return (bool)preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $value);
    }

    private static function isJson($request): bool
    {
        return strpos(strtolower((string)$request->header('Content-Type', '')), 'application/json') !== false;
    }
}
