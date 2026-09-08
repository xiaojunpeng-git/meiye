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
        // This projection intentionally does not relay unrecognized names/conditions.
        // Unknown text must trigger clarification/refusal, never an unfiltered query.
        $lexicon = [
            '现金业绩' => 'cash_performance', '收了多少钱' => 'cash_performance',
            '消耗业绩' => 'consume_amount', '实际业绩' => 'actual_performance',
            '服务业绩' => 'ambiguous_metric', '耗卡业绩' => 'ambiguous_metric',
            '业绩' => 'ambiguous_metric', '今天' => 'TODAY', '昨天' => 'YESTERDAY',
            '本月' => 'THIS_MONTH', '上月' => 'LAST_MONTH', '这月' => 'THIS_MONTH',
            '现金' => 'cash_performance', '消耗' => 'consume_amount',
            '本店' => 'current_store',
            '合计' => 'summary', '汇总' => 'summary', '趋势' => 'trend',
            '排行' => 'ranking', '排名' => 'ranking', '前五' => 'top_5', '后五' => 'bottom_5',
            '最好' => 'top_5', '最差' => 'bottom_5', '对比' => 'comparison',
            'Excel' => 'xlsx', 'excel' => 'xlsx', 'EXCEL' => 'xlsx', '表格' => 'xlsx',
        ];
        $remaining = $question; $signals = [];
        foreach ($lexicon as $text => $code) {
            if (strpos($remaining, $text) !== false) {
                $signals[] = $code; $remaining = str_replace($text, '', $remaining);
            }
        }
        preg_match_all('/(?<![0-9])[0-9]{4}-[0-9]{2}-[0-9]{2}(?![0-9])/', $remaining, $dates);
        foreach ($dates[0] as $date) $remaining = str_replace($date, '', $remaining);
        if (array_intersect($signals,['ranking','top_5','bottom_5'])) $remaining=str_replace(['家店','门店'],'',$remaining);
        $remaining = str_replace(['请帮我', '帮我', '我想知道', '我想查', '查询', '查一下', '看一下', '多少钱', '多少', '当前权限范围', '生成', '导出', '一份', '数据', '一下', '请', '问', '的', '是', '有', '和', '与', '到', '从', '及', '把', '给我', '呢', '那', '？', '?', '。', '，', ',', '、', '！', '!', ' ', "\n", "\r", "\t"], '', $remaining);
        return ['signals' => array_values(array_unique($signals)), 'dates' => array_slice($dates[0], 0, 4),
            'unresolved_condition' => $remaining !== '', 'projection_version' => 'mohe-model-vocabulary-v1'];
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
