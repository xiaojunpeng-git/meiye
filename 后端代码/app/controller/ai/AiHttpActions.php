<?php
namespace app\controller\ai;

use app\services\ai\AiGatewayServices;
use app\services\mobile\protocol\MobileApiResponse;

/** Explicit action list; never route user-controlled operation names to the gateway. */
trait AiHttpActions
{
    public function aiBootstrap() { return $this->callAi('bootstrap'); }
    public function aiCreate() { return $this->callAi('create'); }
    public function aiExecute(string $runId) { return $this->callAi('execute',$runId); }
    public function aiStatus(string $runId) { return $this->callAi('status',$runId); }
    public function aiClarify(string $runId) { return $this->callAi('clarify',$runId); }
    public function aiCancel(string $runId) { return $this->callAi('cancel',$runId); }
    public function aiExport(string $runId) { return $this->callAi('export',$runId); }
    public function aiConfigGet() { return $this->callAi('config_get'); }
    public function aiConfigSave() { return $this->callAi('config_save'); }
    public function aiConfigCheck() { return $this->callAi('config_check'); }

    private function callAi(string $operation,string $runId='')
    {
        try {
            if (strtoupper((string)$this->request->method())==='GET') {
                $query=$this->request->get();
                foreach (['client_session_id','run_delivery_token','generation','window_token','question','history'] as $key) {
                    if (array_key_exists($key,(array)$query)) { throw new \RuntimeException('AI_SENSITIVE_QUERY_FORBIDDEN'); }
                }
                $input=[];
                foreach (['X-Mohe-Ai-Client-Session-Id'=>'client_session_id','X-Mohe-Ai-Run-Delivery-Token'=>'run_delivery_token','X-Mohe-Ai-Generation'=>'generation','X-Mohe-Ai-Window-Token'=>'window_token'] as $header=>$key) {
                    $value=$this->request->header($header,''); if ($value!=='') { $input[$key]=$value; }
                }
            } else {
                // Parse the same JSON representation for POST and PUT; never depend on post() for PUT.
                $raw=$this->request->getContent();
                if (strlen($raw)>262144 || substr(ltrim($raw),0,1)!=='{') { throw new \RuntimeException('AI_INPUT_INVALID'); }
                $input=json_decode($raw,true,32);
                if (json_last_error()!==JSON_ERROR_NONE) { throw new \RuntimeException('AI_INPUT_INVALID'); }
            }
            if (!is_array($input)) { throw new \RuntimeException('AI_INPUT_INVALID'); }
            $context=$this->buildAiContext();
            $context['_refresh']=function () { return $this->refreshAiContext(); };
            $result=$this->aiGateway()->handle($operation,$context,$input,$runId);
            if ($result instanceof \think\Response) { return $result->header(['Cache-Control'=>'no-store']); }
            if (!is_array($result)) { throw new \RuntimeException('AI_RESPONSE_INVALID'); }
            $response=$this->isMobileAi()
                ? MobileApiResponse::success($result,MobileApiResponse::MERCHANT_CONTRACT)
                : app('json')->success('ok',$result);
            return $response->header(['Cache-Control'=>'no-store']);
        } catch (\Throwable $exception) {
            // Never return/log SQL, request content, tokens, prompts or provider errors here.
            $message='魔核 AI 请求未完成，请重试或联系管理员。';
            return ($this->isMobileAi()
                ? json(['contractVersion'=>MobileApiResponse::MERCHANT_CONTRACT,'errorCode'=>'AI_REQUEST_FAILED','message'=>$message],400)
                : app('json')->fail($message))->header(['Cache-Control'=>'no-store']);
        }
    }

    protected function aiGateway() { return new AiGatewayServices(); }
}
