<?php

declare(strict_types=1);

namespace app\controller\mobile;

use app\services\mobile\auth\MobileAuthServices;
use app\services\mobile\protocol\MobileApiResponse;
use mohe\basic\BaseController;
use think\facade\App;

final class MobileAuth extends BaseController
{
    private MobileAuthServices $auth;

    public function __construct(App $app, MobileAuthServices $auth)
    {
        parent::__construct($app);
        $this->auth = $auth;
    }

    public function createCaptcha()
    {
        return $this->mobileSuccess($this->auth->createCaptcha($this->payload(), $this->metadata()));
    }

    public function verifyCaptcha()
    {
        return $this->mobileSuccess($this->auth->verifyCaptcha($this->payload(), $this->metadata()));
    }

    public function createSmsChallenge()
    {
        return $this->mobileSuccess($this->auth->createSmsChallenge($this->payload(), $this->metadata(), (string)$this->request->ip()));
    }

    public function verifySmsChallenge()
    {
        return $this->mobileSuccess($this->auth->verifySmsChallenge($this->payload(), $this->metadata()));
    }

    private function mobileSuccess(array $payload)
    {
        return MobileApiResponse::success($payload, MobileApiResponse::AUTH_CONTRACT);
    }

    private function payload(): array
    {
        $payload = $this->request->post();
        return is_array($payload) ? $payload : [];
    }

    private function metadata(): array
    {
        return (array)($this->request->mobileRequestMetadata ?? []);
    }
}
