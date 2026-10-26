<?php
namespace app\services\ai\registry;

use app\services\ai\contract\AiContractException;

/** Canonical, payload-free helpers. Lists retain their semantic order. */
final class AiRegistryValue
{
    public static function hash(array $value): string
    {
        $encoded=json_encode(self::canonical($value), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        if ($encoded===false) self::fail('AI_REGISTRY_VALUE_INVALID');
        return hash('sha256',$encoded);
    }
    private static function canonical($value)
    {
        if (!is_array($value)) {
            if (is_object($value)||is_resource($value)||is_float($value)) self::fail('AI_REGISTRY_VALUE_INVALID');
            return $value;
        }
        if (!self::isList($value)) ksort($value,SORT_STRING);
        foreach ($value as $key=>$item) $value[$key]=self::canonical($item);
        return $value;
    }
    public static function isList(array $value): bool { return $value===[] || array_keys($value)===range(0,count($value)-1); }
    public static function exact(array $value,array $required,array $optional=[]): void
    {
        if (array_diff($required,array_keys($value)) || array_diff(array_keys($value),array_merge($required,$optional))) self::fail('AI_REGISTRY_SCHEMA_INVALID');
    }
    public static function strings($values,int $max=32): array
    {
        if (!is_array($values)||!self::isList($values)||count($values)>$max) self::fail('AI_REGISTRY_SCHEMA_INVALID');
        foreach ($values as $value) if (!is_string($value)||$value===''||strlen($value)>160) self::fail('AI_REGISTRY_SCHEMA_INVALID');
        if (count(array_unique($values))!==count($values)) self::fail('AI_REGISTRY_SCHEMA_INVALID');
        return $values;
    }
    public static function fail(string $reason): void { throw new AiContractException($reason); }
}
