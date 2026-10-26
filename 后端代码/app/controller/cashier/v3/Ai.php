<?php
namespace app\controller\cashier\v3;

final class Ai extends Report
{
    use \app\controller\ai\AiHttpActions;
    protected function buildAiContext(): array { return $this->aiContext(); }
    protected function isMobileAi(): bool { return false; }
    protected function refreshAiContext(): array
    {
        return app()->make(\app\http\middleware\cashier\AuthTokenMiddleware::class)->handle($this->request,function () {
            $this->initialize();
            return $this->aiContext();
        });
    }
}
