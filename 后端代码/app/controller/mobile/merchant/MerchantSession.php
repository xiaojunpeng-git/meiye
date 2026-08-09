<?php

declare(strict_types=1);

namespace app\controller\mobile\merchant;

use app\services\mobile\merchant\MobileMerchantSessionServices;
use app\services\mobile\protocol\MobileApiResponse;
use mohe\basic\BaseController;
use think\facade\App;

final class MerchantSession extends BaseController
{
    private MobileMerchantSessionServices $sessions;
    public function __construct(App $app, MobileMerchantSessionServices $sessions) { parent::__construct($app); $this->sessions = $sessions; }
    public function passwordLogin() { return $this->root($this->sessions->passwordLogin($this->payload(), $this->metadata())); }
    public function create() { return $this->root($this->sessions->create($this->payload(), $this->metadata())); }
    public function bootstrap() { return $this->root($this->sessions->bootstrap($this->metadata())); }
    public function switchContext() { return $this->root($this->sessions->switchContext($this->payload(), $this->metadata())); }
    public function logout() { return MobileApiResponse::success($this->sessions->logout($this->payload(), $this->metadata()), MobileApiResponse::MERCHANT_CONTRACT); }
    private function root(array $root) { return MobileApiResponse::success($root, MobileApiResponse::MERCHANT_CONTRACT); }
    private function payload(): array { $payload = $this->request->post(); return is_array($payload) ? $payload : []; }
    private function metadata(): array { return (array)($this->request->mobileRequestMetadata ?? []); }
}
