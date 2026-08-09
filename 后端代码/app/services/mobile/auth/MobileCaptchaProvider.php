<?php

declare(strict_types=1);

namespace app\services\mobile\auth;

use app\services\mobile\protocol\MobileApiException;

/** Provider boundary: production must supply a real verifier through env-backed config. */
final class MobileCaptchaProvider
{
    public function verify(string $providerToken, array $input): void
    {
        $testToken = trim((string)config('mobile_auth.test_captcha_token'));
        if ($testToken !== '' && env('APP_DEBUG', false) && hash_equals($testToken, $providerToken)) {
            return;
        }
        if ((string)config('mobile_auth.captcha_provider') === 'ajcaptcha') {
            try {
                aj_captcha_check_two((string)config('mobile_auth.captcha_type'), $providerToken);
                return;
            } catch (\Throwable $exception) {
                throw MobileApiException::protocol('CAPTCHA_PROOF_INVALID', '人机校验未通过，请重新验证。', 'captchaProviderToken');
            }
        }
        throw MobileApiException::protocol('CAPTCHA_PROOF_INVALID', '人机校验未通过，请重新验证。', 'captchaProviderToken');
    }
}
