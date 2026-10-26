<?php

declare(strict_types=1);

namespace app\services\mobile\merchant;

use app\services\mobile\protocol\MobileApiException;
use EasyWeChat\Factory;
use mohe\services\wechat\MiniProgram;
use Symfony\Component\Cache\Adapter\RedisAdapter;
use think\facade\Cache;
use think\facade\Db;
use Yurun\Util\Swoole\Guzzle\SwooleHandler;

/** Exchanges a one-use WeChat phone code for a verified employee phone on this instance. */
final class MobileMerchantWechatPhoneServices
{
    public function verifiedPhone(string $code): string
    {
        if ($code === '' || strlen($code) > 512) {
            throw MobileApiException::protocol('INVALID_REQUEST_FIELD', '微信手机号授权凭据无效。', 'phoneCode');
        }
        [$appid, $secret, $merchantProgram] = self::miniProgramCredentials(
            (string)sys_config('merchant_mini_appid', ''),
            (string)sys_config('merchant_mini_appsecret', ''),
            (string)sys_config('routine_appId', ''),
            (string)sys_config('routine_appsecret', '')
        );
        if ($appid === '' || $secret === '') {
            throw MobileApiException::business('WECHAT_PHONE_UNAVAILABLE', '当前小程序尚未配置微信手机号登录。');
        }
        try {
            // The instance's mini-program access token redeems this one-use
            // code. This service does not write the code or phone to audit data.
            if ($merchantProgram) {
                // Keep provider transport and cache consistent with the
                // existing Swoole mini-program client, while isolating this
                // merchant AppID from member mini-program operations.
                $application = Factory::miniProgram(['app_id' => $appid, 'secret' => $secret, 'response_type' => 'array']);
                $application['guzzle_handler'] = SwooleHandler::class;
                $application->rebind('cache', new RedisAdapter(Cache::store('redis')->handler()));
            } else {
                $application = MiniProgram::instance()->application();
            }
            $response = $application->phone_number->getUserPhoneNumber($code);
        } catch (\Throwable $exception) {
            throw MobileApiException::business('WECHAT_PHONE_UNAVAILABLE', '微信手机号验证暂不可用，请使用账号密码登录。');
        }
        if (is_object($response) && method_exists($response, 'toArray')) {
            $response = $response->toArray();
        }
        return self::phoneFromResponse(is_array($response) ? $response : [], $appid);
    }

    /**
     * A separate merchant mini-program must use its own AppID and secret.
     * An incomplete pair fails closed; only instances without either override
     * keep the original routine credentials used by the accepted R10A path.
     */
    public static function miniProgramCredentials(
        string $merchantAppId,
        string $merchantSecret,
        string $routineAppId,
        string $routineSecret
    ): array {
        $merchantAppId = trim($merchantAppId);
        $merchantSecret = trim($merchantSecret);
        if ($merchantAppId !== '' || $merchantSecret !== '') {
            return [$merchantAppId, $merchantSecret, true];
        }
        return [trim($routineAppId), trim($routineSecret), false];
    }

    /** Validate the provider response before it can become a merchant identity. */
    public static function phoneFromResponse(array $response, string $appid): string
    {
        if ((int)($response['errcode'] ?? -1) !== 0 || !is_array($response['phone_info'] ?? null)) {
            throw MobileApiException::business('WECHAT_PHONE_VERIFICATION_FAILED', '手机号授权未完成，请重试或使用账号密码登录。');
        }
        $info = $response['phone_info'];
        $phone = trim((string)($info['purePhoneNumber'] ?? ''));
        $countryCode = (string)($info['countryCode'] ?? '');
        $watermark = is_array($info['watermark'] ?? null) ? $info['watermark'] : [];
        if ($countryCode !== '86' || !preg_match('/^1[3-9][0-9]{9}$/', $phone)
            || ($watermark !== [] && !hash_equals($appid, (string)($watermark['appid'] ?? '')))) {
            throw MobileApiException::business('WECHAT_PHONE_VERIFICATION_FAILED', '微信返回的手机号无法用于员工登录。');
        }
        return $phone;
    }

    /** An ambiguous or deleted employee phone must never select a principal. */
    public function employeeIdForPhone(string $phone): int
    {
        $rows = Db::name('employee')->where('phone', $phone)->where('is_del', 0)
            ->field('id,status')->limit(2)->select()->toArray() ?: [];
        return self::employeeIdFromRows($rows);
    }

    /** Keep the duplicate/disabled policy testable without a live customer DB. */
    public static function employeeIdFromRows(array $rows): int
    {
        if (count($rows) !== 1) {
            throw MobileApiException::business('EMPLOYEE_PHONE_NOT_UNIQUE', '该手机号未对应唯一员工，请使用账号密码登录并联系管理员核对档案。');
        }
        if ((int)($rows[0]['id'] ?? 0) <= 0) {
            throw MobileApiException::business('EMPLOYEE_PHONE_NOT_UNIQUE', '该手机号未对应唯一员工，请使用账号密码登录并联系管理员核对档案。');
        }
        if ((int)($rows[0]['status'] ?? 0) !== 1) {
            throw MobileApiException::business('EMPLOYEE_DISABLED', '员工已停用，请联系管理员。');
        }
        return (int)$rows[0]['id'];
    }
}
