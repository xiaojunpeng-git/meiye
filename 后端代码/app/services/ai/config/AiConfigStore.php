<?php
namespace app\services\ai\config;

use PDO;
use RuntimeException;

/** Persistent service config only; public projection never exposes the API key. */
final class AiConfigStore
{
    public const QUESTION_SCOPE = 'sanitized-question-v1';
    private $db; private $table; private $instance; private $private;
    public function __construct(PDO $db, string $prefix, string $instance, AiPrivateStorage $private)
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || $instance === '') throw new RuntimeException('AI_CONFIG_INVALID');
        $this->db = $db; $this->table = $prefix . 'mohe_ai_config'; $this->instance = $instance; $this->private = $private;
    }
    public function read(bool $secret = false): array
    {
        $statement = $this->db->prepare('SELECT * FROM ' . $this->table . ' WHERE instance_id=?'); $statement->execute([$this->instance]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        $scopeSupported = $row ? array_key_exists('external_scope_version', $row) : $this->scopeSupported();
        if (!$row) return ['enabled' => false, 'model' => '', 'has_api_key' => false, 'external_processing_authorized' => false,
            'external_scope_version' => '', 'external_scope_supported' => $scopeSupported, 'version' => 0];
        $result = ['enabled' => (bool)$row['enabled'], 'model' => $row['model'], 'has_api_key' => $row['encrypted_key'] !== '',
            'external_processing_authorized' => (bool)$row['external_authorized'], 'version' => (int)$row['version'],
            'external_scope_version' => (bool)$row['external_authorized'] && ($row['external_scope_version'] ?? '') === self::QUESTION_SCOPE ? self::QUESTION_SCOPE : '',
            'external_scope_supported' => $scopeSupported];
        if ($secret) {
            $bytes = base64_decode($row['encrypted_key'], true);
            $key = is_string($bytes) ? openssl_decrypt(substr($bytes, 28), 'aes-256-gcm', $this->private->signingKey(), OPENSSL_RAW_DATA, substr($bytes, 0, 12), substr($bytes, 12, 16), $this->instance) : false;
            if ($key === false) throw new RuntimeException('AI_MODEL_CONFIG_INVALID');
            $result['api_key'] = $key;
        }
        return $result;
    }
    public function save(array $input): array
    {
        $keys = array_keys($input); sort($keys);
        if (array_diff($keys, ['enabled', 'external_processing_authorized', 'external_scope_version', 'model', 'api_key', 'version'])
            || !is_bool($input['enabled'] ?? null) || !is_bool($input['external_processing_authorized'] ?? null)
            || !is_string($input['model'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/-]{0,127}$/D', $input['model'])
            || !is_int($input['version'] ?? null) || $input['version'] < 0) throw new RuntimeException('AI_CONFIG_INVALID');
        if ($input['enabled'] && !$input['external_processing_authorized']) throw new RuntimeException('AI_EXTERNAL_AUTHORIZATION_REQUIRED');
        $current = $this->read(true);
        if ($current['version'] !== $input['version']) throw new RuntimeException('AI_CONFIG_VERSION_CONFLICT');
        // Legacy clients may save old settings but can never opt into a new payload.
        $scope = $input['external_scope_version'] ?? $current['external_scope_version'];
        if (array_key_exists('external_scope_version', $input) && !in_array($input['external_scope_version'], ['', self::QUESTION_SCOPE], true)) throw new RuntimeException('AI_CONFIG_INVALID');
        if (!$input['external_processing_authorized']) $scope = '';
        if ($scope !== '' && !$current['external_scope_supported']) throw new RuntimeException('AI_CONFIG_SCOPE_MIGRATION_REQUIRED');
        $key = $input['api_key'] ?? '';
        if (!is_string($key) || strlen($key) > 1024 || preg_match('/[\x00-\x20\x7f]/', $key)) throw new RuntimeException('AI_CONFIG_INVALID');
        if ($key === '') $key = $current['api_key'] ?? '';
        if ($key === '') throw new RuntimeException('AI_MODEL_KEY_REQUIRED');
        $nonce = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($key, 'aes-256-gcm', $this->private->signingKey(), OPENSSL_RAW_DATA, $nonce, $tag, $this->instance);
        if ($cipher === false) throw new RuntimeException('AI_CONFIG_INVALID');
        $values = [(int)$input['enabled'], $input['model'], base64_encode($nonce . $tag . $cipher), (int)$input['external_processing_authorized'], $current['version'] + 1];
        $scopeColumn = $current['external_scope_supported'] ? ',external_scope_version' : '';
        if ($current['external_scope_supported']) $values[] = $scope;
        $values[] = $this->instance;
        if ($current['version'] === 0) {
            $statement = $this->db->prepare('INSERT INTO ' . $this->table . ' (enabled,model,encrypted_key,external_authorized,version' . $scopeColumn . ',instance_id) VALUES (' . implode(',', array_fill(0, count($values), '?')) . ')');
        } else {
            $values[] = $current['version'];
            $statement = $this->db->prepare('UPDATE ' . $this->table . ' SET enabled=?,model=?,encrypted_key=?,external_authorized=?,version=?' . ($scopeColumn !== '' ? ',external_scope_version=?' : '') . ' WHERE instance_id=? AND version=?');
        }
        $statement->execute($values);
        if ($statement->rowCount() !== 1) throw new RuntimeException('AI_CONFIG_VERSION_CONFLICT');
        return $this->read();
    }

    private function scopeSupported(): bool
    {
        try {
            $statement = $this->db->query('SELECT external_scope_version FROM ' . $this->table . ' WHERE 1=0');
            if ($statement !== false) return true;
            // PHP 7.4 PDO defaults to silent errors; do not treat false as support.
            $info = $this->db->errorInfo();
            if ((int)($info[1] ?? 0) === 1054 || ((int)($info[1] ?? 0) === 1 && strpos($info[2] ?? '', 'no such column: external_scope_version') !== false)) return false;
            throw new RuntimeException('AI_CONFIG_UNAVAILABLE');
        }
        catch (\PDOException $e) {
            // Only the known missing-column condition is backwards-compatible.
            $info = $e->errorInfo;
            if (($info[1] ?? null) === 1054 || (($info[1] ?? null) === 1 && strpos($e->getMessage(), 'no such column: external_scope_version') !== false)) return false;
            throw $e;
        }
    }

    /** Must be checked immediately before preparing any expanded outbound payload. */
    public static function allowsSanitizedQuestion(array $configuration): bool
    {
        return ($configuration['enabled'] ?? false) === true
            && ($configuration['external_processing_authorized'] ?? false) === true
            && ($configuration['external_scope_supported'] ?? false) === true
            && ($configuration['external_scope_version'] ?? '') === self::QUESTION_SCOPE;
    }

    /** Administrative probe is an outbound Attempt, not a conversation or business Run. */
    public function beginProbe(int $version): string
    {
        if ($this->db->inTransaction()) throw new RuntimeException('AI_CONFIG_PROBE_BUSY');
        $attempt=substr($this->table,0,-6).'attempt'; $mutex=substr($this->table,0,-6).'mutex';
        $sqlite=$this->db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'; $now=(int)floor(microtime(true)*1000);
        if ($sqlite) $this->db->exec('BEGIN IMMEDIATE'); else $this->db->beginTransaction();
        try {
            $s=$this->db->prepare(($sqlite?'INSERT OR IGNORE':'INSERT IGNORE').' INTO '.$mutex.' (instance_id) VALUES (?)'); $s->execute([$this->instance]);
            $s=$this->db->prepare('SELECT instance_id FROM '.$mutex.' WHERE instance_id=?'.($sqlite?'':' FOR UPDATE')); $s->execute([$this->instance]); $s->fetch();
            $s=$this->db->prepare('SELECT COUNT(*) FROM '.$attempt.' WHERE instance_id=? AND target_code=? AND created_at>?');
            $s->execute([$this->instance,'siliconflow_probe',$now-60000]);
            if ((int)$s->fetchColumn()>0) throw new RuntimeException('AI_CHECK_RATE_LIMITED');
            $id=bin2hex(random_bytes(24));
            $s=$this->db->prepare('INSERT INTO '.$attempt.' (instance_id,run_id,attempt_code,kind,target_code,payload_hash,state,input_tokens,output_tokens,created_at,expires_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            $s->execute([$this->instance,$id,'config_probe','model','siliconflow_probe',hash_hmac('sha256','config-probe-v1:'.$version,$this->private->signingKey()),'IN_FLIGHT',null,null,$now,$now+86400000]);
            if ($sqlite) $this->db->exec('COMMIT'); else $this->db->commit();
            return $id;
        } catch (\Throwable $e) {
            if ($sqlite) $this->db->exec('ROLLBACK'); elseif ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
    }
    public function finishProbe(string $id,string $state,?int $inputTokens=null,?int $outputTokens=null): void
    {
        if (!preg_match('/^[a-f0-9]{48}$/D',$id) || !in_array($state,['SUCCEEDED','FAILED','UNKNOWN'],true)
            || ($inputTokens!==null && $inputTokens<0) || ($outputTokens!==null && $outputTokens<0)) throw new RuntimeException('AI_PROBE_RESULT_INVALID');
        $s=$this->db->prepare('UPDATE '.substr($this->table,0,-6).'attempt SET state=?,input_tokens=?,output_tokens=? WHERE instance_id=? AND run_id=? AND attempt_code=? AND state=?');
        $s->execute([$state,$inputTokens,$outputTokens,$this->instance,$id,'config_probe','IN_FLIGHT']);
    }
}
