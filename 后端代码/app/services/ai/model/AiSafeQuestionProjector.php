<?php
namespace app\services\ai\model;

use app\services\ai\config\AiConfigStore;

/**
 * Produces a de-identified natural-language question. This boundary masks
 * server-known private objects and deterministic credentials/identifiers, but
 * never rebuilds the sentence from an allowlist of phrases.
 */
final class AiSafeQuestionProjector
{
    // The browser remains the complete 24-hour conversation store.  The
    // external model receives a deliberately smaller recency window because
    // the signed prior query carries the executable context independently.
    // Six prior questions cover a five-round follow-up while keeping an old
    // local transcript from inflating every provider request.
    private const MODEL_RECENT_QUESTION_LIMIT = 6;

    /**
     * The device keeps the conversation locally.  When the customer has
     * explicitly authorized the external model, only de-identified *questions*
     * from that local context are supplied to it.  Previous answers can contain
     * figures and names, so they never cross this boundary.
     */
    public function projectConversation(string $question,array $history,array $configuration,array $privateLabels=[]): array
    {
        // One conversation has one reference map.  A name mentioned in an
        // earlier question must keep the same opaque reference when the next
        // question uses a pronoun, while a newly mentioned name gets a new one.
        if (count($history)>20) throw new \RuntimeException('AI_CONVERSATION_INVALID');
        $history=array_slice($history,-self::MODEL_RECENT_QUESTION_LIMIT);
        $references=[];
        $recent=[];
        foreach ($history as $round) {
            if (!is_array($round) || !is_string($round['question']??null)) throw new \RuntimeException('AI_CONVERSATION_INVALID');
            $past=$this->projectWithReferences($round['question'],$configuration,$privateLabels,$references);
            $recent[]=$past['outbound']['question'];
        }
        $current=$this->projectWithReferences($question,$configuration,$privateLabels,$references);
        $current['outbound']['recent_questions']=$recent;
        $messages=[['id'=>'current','text'=>$current['outbound']['question']]];
        foreach ($recent as $index=>$text) $messages[]=['id'=>'recent_'.($index+1),'text'=>$text];
        // A de-identified, request-scoped evidence projection. It never
        // contains answers, result rows, entity IDs or server reference values.
        $current['outbound']['evidence_messages']=$messages;
        // Current conditions are the only ones that can block this request.
        // The complete map is retained locally so a model can safely refer to
        // a de-identified object that first appeared in the conversation.
        $current['reference_values']=$references;
        return $current;
    }

    public function project(string $question,array $configuration,array $privateLabels=[]): array
    {
        $references=[];
        return $this->projectWithReferences($question,$configuration,$privateLabels,$references);
    }

    private function projectWithReferences(string $question,array $configuration,array $privateLabels,array &$references): array
    {
        if (!AiConfigStore::allowsSanitizedQuestion($configuration)) throw new \RuntimeException('AI_EXTERNAL_SCOPE_REQUIRED');
        if ($question==='' || strlen($question)>8192 || preg_match('//u',$question)!==1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$question)) throw new \RuntimeException('AI_CONVERSATION_INVALID');
        foreach ($privateLabels as $label) if (!is_string($label)||$label===''||strlen($label)>512||preg_match('//u',$label)!==1) throw new \RuntimeException('AI_PRIVATE_LABEL_INVALID');
        $privateLabels=array_values(array_unique($privateLabels));
        usort($privateLabels,static function(string $left,string $right):int{return strlen($right)<=>strlen($left);});

        $local=[];$safe=$question;
        $reference=static function(string $value) use (&$local,&$references):string {
            $ref=array_search($value,$references,true);
            if ($ref===false) {
                $ref='local_condition_'.(count($references)+1);
                $references[$ref]=$value;
            }
            $local[$ref]=$value;
            return '['.$ref.']';
        };
        foreach ([
            '/\bsk-[A-Za-z0-9_-]{12,}\b/u',
            '/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu',
            '/(?<![0-9])1[3-9][0-9]{9}(?![0-9])/u',
            '/(?<![A-Za-z0-9])[A-Za-z0-9][A-Za-z0-9_-]{15,}(?![A-Za-z0-9])/u',
        ] as $pattern) $safe=preg_replace_callback($pattern,static function(array $match) use($reference):string{return $reference($match[0]);},$safe);
        foreach ($privateLabels as $label) {
            if (mb_strpos($safe,$label,0,'UTF-8')===false) continue;
            $ref=$reference($label);$safe=str_replace($label,$ref,$safe);
        }
        $safe=preg_replace('/\s+/u',' ',trim($safe));
        if (!is_string($safe)||$safe===''||strlen($safe)>16384) throw new \RuntimeException('AI_CONVERSATION_INVALID');
        return [
            'outbound'=>['schema_version'=>'sanitized-question-v2','question'=>$safe,'has_unresolved_conditions'=>(bool)$local,'server_resolved_fields'=>[]],
            'local_conditions'=>$local,
        ];
    }
}
