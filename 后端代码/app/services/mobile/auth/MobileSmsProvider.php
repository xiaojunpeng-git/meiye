<?php

declare(strict_types=1);

namespace app\services\mobile\auth;

use app\services\mobile\protocol\MobileApiException;
use app\services\serve\ServeServices;

/**
 * Strict adapter for the configured provider. It never converts an exception
 * to a successful send; callers invoke it only after the challenge commit.
 */
final class MobileSmsProvider
{
    /** @return array{state:string,requestDigest:string} */
    public function send(string $phone, string $code): array
    {
        $provider = trim((string)config('mobile_auth.provider'));
        if ($provider === 'test') {
            if (!env('APP_DEBUG', false) || trim((string)config('mobile_auth.test_sms_code')) === '') {
                throw MobileApiException::business('SMS_PROVIDER_FAILED', '测试短信供应商未被允许。');
            }
            return ['state' => 'SENT', 'requestDigest' => hash('sha256', 'test:' . $phone . ':' . time())];
        }
        $template = trim((string)config('mobile_auth.sms_template_id'));
        if ($provider === '' || $template === '') {
            throw MobileApiException::business('SMS_PROVIDER_FAILED', '短信服务尚未完成配置。');
        }
        try {
            /** @var ServeServices $serve */
            $serve = app()->make(ServeServices::class);
            $result = $serve->sms($provider)->send($phone, $template, ['code' => $code]);
            if ($result === false) {
                throw MobileApiException::business('SMS_PROVIDER_FAILED', '短信发送失败，请稍后重试。');
            }
            return ['state' => 'SENT', 'requestDigest' => hash('sha256', $provider . ':' . $phone . ':' . serialize($result))];
        } catch (MobileApiException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            // A transport exception gives no reliable delivery result.
            throw MobileApiException::business('SMS_PROVIDER_UNKNOWN', '短信发送结果未知，请勿重复提交。');
        }
    }
}
