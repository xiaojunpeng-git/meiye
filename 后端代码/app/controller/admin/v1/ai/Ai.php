<?php
namespace app\controller\admin\v1\ai;

final class Ai extends \app\controller\admin\v1\report\UnifiedReport
{
    use \app\controller\ai\AiHttpActions;
    protected function buildAiContext(): array { return $this->aiContext(); }
    protected function isMobileAi(): bool { return false; }
    protected function refreshAiContext(): array
    {
        return app()->make(\app\http\middleware\admin\AdminAuthTokenMiddleware::class)->handle($this->request,function () {
            $this->initialize();
            return $this->aiContext();
        });
    }
}
