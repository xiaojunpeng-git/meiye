<?php
namespace app\services\ai\execution;

use RuntimeException;

/**
 * The queue boundary has one deliberately small, canonical request shape.
 * It is not an intent grammar: the model still interprets the customer's
 * language.  It only removes HTTP/session credentials that a trusted worker
 * must never inherit.
 */
final class AiExecutionEnvelope
{
    public static function project(string $operation,array $input): array
    {
        $keys=[
            'execute'=>['question','history','output_format','guidance_schema_version','context_ref'],
            'clarify'=>['clarification_id','choices','schema_version','step_revision','intent_revision','client_submission_id','revise_clarification_id'],
        ][$operation]??null;
        if ($keys===null) throw new RuntimeException('AI_OPERATION_INVALID');
        $projected=[];
        foreach ($keys as $key) if (array_key_exists($key,$input)) $projected[$key]=$input[$key];
        return $projected;
    }

    /** A keyed canonical hash is used by acknowledgement, replay and Worker validation. */
    public static function hash(string $operation,array $input,string $key): string
    {
        if ($key==='' || strlen($key)<16) throw new RuntimeException('AI_EXECUTION_REQUEST_INVALID');
        $json=json_encode(self::canonical(self::project($operation,$input)),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) throw new RuntimeException('AI_EXECUTION_REQUEST_INVALID');
        return hash_hmac('sha256',$json,$key);
    }

    private static function canonical($value)
    {
        if (!is_array($value)) return $value;
        // Keep the envelope usable on PHP runtimes that predate array_is_list.
        if ($value===[] || array_keys($value)===range(0,count($value)-1)) return array_map([self::class,'canonical'],$value);
        ksort($value,SORT_STRING);
        foreach ($value as $key=>$item) $value[$key]=self::canonical($item);
        return $value;
    }
}
