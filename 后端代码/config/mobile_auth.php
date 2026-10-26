<?php

use think\facade\Env;

return [
    // Secrets are deliberately environment-only. Production refuses the test provider.
    'hmac_key' => trim((string)Env::get('mobile_auth.hmac_key', '')),
    'hmac_key_version' => trim((string)Env::get('mobile_auth.hmac_key_version', 'v1')),
    'app_session_seconds' => max(300, (int)Env::get('mobile_auth.app_session_seconds', 2592000)),
    'merchant_session_seconds' => max(300, (int)Env::get('mobile_auth.merchant_session_seconds', 28800)),
    'captcha_seconds' => 300,
    'captcha_provider' => trim((string)Env::get('mobile_auth.captcha_provider', 'ajcaptcha')),
    'captcha_type' => trim((string)Env::get('mobile_auth.captcha_type', 'blockPuzzle')),
    'sms_seconds' => 300,
    'provider' => trim((string)Env::get('mobile_auth.sms_provider', 'aliyun')),
    'sms_template_id' => trim((string)Env::get('mobile_auth.sms_template_id', '')),
    'test_sms_code' => trim((string)Env::get('mobile_auth.test_sms_code', '')),
    'test_captcha_token' => trim((string)Env::get('mobile_auth.test_captcha_token', '')),
];
