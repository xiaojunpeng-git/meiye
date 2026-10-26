<?php
namespace app\services\ai\model;

use app\services\ai\contract\AiContractException;

/** Only enumerated business vocabulary leaves the instance, never raw conversation. */
final class AiModelInputProjector
{
    public function validateConversation($question, $history): array
    {
        if (!is_string($question) || trim($question) === '' || strlen($question) > 8192
            || preg_match('//u', $question) !== 1 || !is_array($history) || count($history) > 20
            || ($history && array_keys($history) !== range(0, count($history) - 1))) {
            throw new AiContractException('AI_CONVERSATION_INVALID');
        }
        $bytes = strlen($question);
        foreach ($history as $round) {
            if (!is_array($round) || !isset($round['question']) || !is_string($round['question'])
                || !array_key_exists('answer', $round) || (!is_string($round['answer']) && !is_array($round['answer']))) {
                throw new AiContractException('AI_CONVERSATION_INVALID');
            }
            $keys = array_keys($round); sort($keys);
            if ($keys !== ['answer', 'question']) throw new AiContractException('AI_CONVERSATION_INVALID');
            $encoded = json_encode($round, JSON_UNESCAPED_UNICODE);
            if ($encoded === false || strlen($encoded) > 32768) throw new AiContractException('AI_CONVERSATION_INVALID');
            $bytes += strlen($encoded);
        }
        if ($bytes > 131072) throw new AiContractException('AI_CONVERSATION_TOO_LARGE');
        return ['question' => trim($question), 'history' => $history];
    }

    public function project(string $question): array
    {
        require_once dirname(__DIR__) . '/semantic/AiSemanticIntentParser.php';
        return (new \app\services\ai\semantic\AiSemanticIntentParser())->parse($question);
    }

    public function modelView(array $conversation): array
    {
        $history = [];
        foreach ($conversation['history'] as $round) {
            // Assistant answers may contain money, names or malicious instructions:
            // do not forward them, including local answer metadata, to the vendor.
            $history[] = $this->project($round['question']);
        }
        return ['current' => $this->project($conversation['question']), 'recent_user_intents' => $history];
    }
}
