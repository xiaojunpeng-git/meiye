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
    public function project(string $question,array $configuration,array $privateLabels=[]): array
    {
        if (!AiConfigStore::allowsSanitizedQuestion($configuration)) throw new \RuntimeException('AI_EXTERNAL_SCOPE_REQUIRED');
        if ($question==='' || strlen($question)>8192 || preg_match('//u',$question)!==1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$question)) throw new \RuntimeException('AI_CONVERSATION_INVALID');
        foreach ($privateLabels as $label) if (!is_string($label)||$label===''||strlen($label)>512||preg_match('//u',$label)!==1) throw new \RuntimeException('AI_PRIVATE_LABEL_INVALID');
        $privateLabels=array_values(array_unique($privateLabels));
        usort($privateLabels,static function(string $left,string $right):int{return strlen($right)<=>strlen($left);});

        $local=[];$safe=$question;
        $reference=static function(string $value) use (&$local):string {
            $ref='local_condition_'.(count($local)+1);$local[$ref]=$value;return '['.$ref.']';
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
