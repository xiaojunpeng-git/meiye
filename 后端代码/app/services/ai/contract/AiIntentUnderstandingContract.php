<?php
namespace app\services\ai\contract;

/**
 * Non-executable business understanding. This carrier deliberately knows no
 * metric registry, object IDs, date contract, query syntax or permission scope.
 * Its grounded fragments may explain a binding gap, never authorize a query.
 */
final class AiIntentUnderstandingContract
{
    public static function modelInstruction(): string
    {
        return 'Include understanding to preserve the business meaning independently of executable capability availability: '
            . '{"goal":"brief business goal in the customer language","evidence_fragments":["exact text from the safe current or relevant recent customer question"],"status":"understood|needs_clarification"}. '
            . 'These are the only three keys; do not put metric codes, object IDs, date fields, formulas, SQL or executable steps inside understanding. '
            . 'goal is at most 240 characters and evidence_fragments contains 1 to 8 distinct verbatim fragments of at most 160 characters each. '
            . 'understood means that the intended business outcome is clear, not that data exists or that every execution condition is ready. '
            . 'needs_clarification means the business meaning itself has multiple plausible interpretations. '
            . 'An understood request with no faithful registered metric binding keeps metric_codes empty and needs_metric_choice false; preserve its meaning in understanding, not in unresolved_fragments. '
            . 'Do not invent an unregistered code or choose a nearby available metric to make a request executable. '
            . 'The server independently validates and binds registered capabilities after understanding; understanding is never an execution grant.';
    }

    public static function normalize($value, array $safeQuestion): array
    {
        if ($value instanceof \stdClass) $value = get_object_vars($value);
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys, SORT_STRING);
        if ($keys !== ['evidence_fragments', 'goal', 'status']) self::fail('shape');
        if (!self::text($value['goal'], 240)) self::fail('goal');
        if (!in_array($value['status'], ['understood', 'needs_clarification'], true)) self::fail('status');
        $fragments = $value['evidence_fragments'];
        if (!is_array($fragments) || count($fragments) < 1 || count($fragments) > 8
            || array_keys($fragments) !== range(0, count($fragments) - 1)) self::fail('evidence_fragments');
        $texts = array_merge([(string)($safeQuestion['question'] ?? '')], (array)($safeQuestion['recent_questions'] ?? []));
        $seen = [];
        foreach ($fragments as $fragment) {
            if (!self::text($fragment, 160) || in_array($fragment, $seen, true)) self::fail('evidence_fragments');
            $grounded = false;
            foreach ($texts as $text) {
                if (is_string($text) && mb_strpos($text, $fragment, 0, 'UTF-8') !== false) {
                    $grounded = true;
                    break;
                }
            }
            if (!$grounded) self::fail('evidence_not_verbatim');
            $seen[] = $fragment;
        }
        return ['goal' => trim($value['goal']), 'evidence_fragments' => $fragments, 'status' => $value['status']];
    }

    private static function text($value, int $maximum): bool
    {
        return is_string($value) && trim($value) !== '' && preg_match('//u', $value) === 1
            && mb_strlen($value, 'UTF-8') <= $maximum && !preg_match('/[\x00-\x1f\x7f]/', $value);
    }

    private static function fail(string $predicate): void
    {
        throw new AiContractException('AI_MODEL_INTENT_CONTRACT_INVALID', [
            'stage' => 'intent_contract', 'predicate' => 'bad_value:understanding.' . $predicate,
        ]);
    }
}
