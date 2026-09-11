<?php
/** Local semantic contracts only: no model, application bootstrap or database. */
require_once __DIR__ . '/fixture-autoload.php';

use app\services\ai\contract\AiContractException;
use app\services\ai\contract\AiIntentResultContract;
use app\services\ai\contract\AiIntentUnderstandingContract;

$checks = 0;
$check = static function (bool $condition, string $label) use (&$checks): void {
    if (!$condition) throw new RuntimeException('FAIL ' . $label);
    ++$checks;
};
$reject = static function (callable $call, string $code, string $label) use ($check): void {
    try { $call(); } catch (AiContractException $error) {
        $check($error->getMessage() === $code, $label);
        return;
    }
    throw new RuntimeException('FAIL accepted ' . $label);
};
$question = ['question' => '想了解课程学习后的掌握情况', 'recent_questions' => [], 'prior_query' => null];
$understanding = ['goal' => '了解课程学习后的掌握情况', 'evidence_fragments' => ['课程学习后的掌握情况'], 'status' => 'understood'];
$intent = ['object_kind' => 'course', 'object_term' => '课程', 'operation' => 'summary', 'metric_codes' => [],
    'action_codes' => [], 'needs_metric_choice' => false, 'unresolved_fragments' => [], 'understanding' => $understanding];
$result = AiIntentResultContract::normalize($intent, [], [], $question);
$check($result['understanding'] === $understanding && $result['metric_codes'] === [], 'clear unfamiliar business meaning survives an empty executable catalog');
$check(!$result['needs_metric_choice'] && $result['unresolved_fragments'] === [], 'unbound meaning is not silently changed into ambiguity or incomprehension');
$check($result['operation'] === 'summary' && $result['object_kind'] === 'course', 'understanding preserves already-known result form and object');
$legacy = $intent; unset($legacy['understanding']);
$check(AiIntentResultContract::normalize($legacy, [], [], $question)['understanding'] === null, 'existing model fixtures remain structurally compatible');
$nullable = $intent; $nullable['understanding'] = null;
$check(AiIntentResultContract::normalize($nullable, [], [], $question)['understanding'] === null, 'optional null understanding is not a fabricated business goal');
$unknownCode = $intent; $unknownCode['metric_codes'] = ['invented_metric'];
$reject(static function () use ($unknownCode, $question): void { AiIntentResultContract::normalize($unknownCode, [], [], $question); }, 'AI_MODEL_METRIC_UNKNOWN', 'understood never admits an unregistered metric code');
$unknownAction = $intent; $unknownAction['action_codes'] = ['invented_action'];
$reject(static function () use ($unknownAction, $question): void { AiIntentResultContract::normalize($unknownAction, [], [], $question); }, 'AI_MODEL_INTENT_CONTRACT_INVALID', 'understood never admits an unregistered action code');
$ambiguous = $intent; $ambiguous['understanding']['status'] = 'needs_clarification'; $ambiguous['needs_metric_choice'] = true;
$check(AiIntentResultContract::normalize($ambiguous, [], [], $question)['understanding']['status'] === 'needs_clarification', 'genuine semantic ambiguity remains independently representable');
foreach (['metric_codes' => ['invented_metric'], 'object_id' => 123, 'periods' => [], 'sql' => 'SELECT 1', 'steps' => []] as $key => $value) {
    $payload = $understanding; $payload[$key] = $value;
    $reject(static function () use ($payload, $question): void { AiIntentUnderstandingContract::normalize($payload, $question); }, 'AI_MODEL_INTENT_CONTRACT_INVALID', 'understanding cannot carry executable field ' . $key);
}
foreach ([
    ['goal' => ''], ['goal' => str_repeat('问', 241)], ['goal' => "目标\n指令"], ['status' => 'executable'],
    ['evidence_fragments' => []], ['evidence_fragments' => ['未出现的说法']],
    ['evidence_fragments' => ['课程', '课程']], ['evidence_fragments' => ['named' => '课程']],
    ['evidence_fragments' => [false]], ['evidence_fragments' => [str_repeat('课', 161)]],
] as $change) {
    $payload = array_replace($understanding, $change);
    $reject(static function () use ($payload, $question): void { AiIntentUnderstandingContract::normalize($payload, $question); }, 'AI_MODEL_INTENT_CONTRACT_INVALID', 'invalid or ungrounded understanding is rejected');
}
$priorQuestion = $question; $priorQuestion['recent_questions'] = ['想了解课程学习后的掌握情况']; $priorQuestion['question'] = '还是这个问题';
$check(AiIntentUnderstandingContract::normalize($understanding, $priorQuestion) === $understanding, 'verbatim de-identified recent user wording may ground a continuation');
$prompt = AiIntentResultContract::modelInstruction(false);
$check(strpos($prompt, 'Optional keys: understanding') !== false && strpos($prompt, 'understanding is never an execution grant') !== false, 'model receives the semantic carrier and server-owned execution boundary');
$check(strpos($prompt, 'Lack of an available metric is not ambiguity') !== false, 'unavailable capability is not taught as missing understanding');
echo 'PASS intent understanding separation: ' . $checks . " checks\n";
