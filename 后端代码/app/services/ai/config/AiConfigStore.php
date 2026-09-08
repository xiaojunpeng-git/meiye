<?php
namespace app\services\ai\config;

use PDO;
use RuntimeException;

/** Persistent service config only; public projection never exposes the API key. */
final class AiConfigStore
{
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
        if (!$row) return ['enabled' => false, 'model' => '', 'has_api_key' => false, 'external_processing_authorized' => false, 'version' => 0];
        $result = ['enabled' => (bool)$row['enabled'], 'model' => $row['model'], 'has_api_key' => $row['encrypted_key'] !== '',
            'external_processing_authorized' => (bool)$row['external_authorized'], 'version' => (int)$row['version']];
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
        if (array_diff($keys, ['enabled', 'external_processing_authorized', 'model', 'api_key', 'version'])
            || !is_bool($input['enabled'] ?? null) || !is_bool($input['external_processing_authorized'] ?? null)
            || !is_string($input['model'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\/-]{0,127}$/D', $input['model'])
            || !is_int($input['version'] ?? null) || $input['version'] < 0) throw new RuntimeException('AI_CONFIG_INVALID');
        if ($input['enabled'] && !$input['external_processing_authorized']) throw new RuntimeException('AI_EXTERNAL_AUTHORIZATION_REQUIRED');
        $current = $this->read(true);
        if ($current['version'] !== $input['version']) throw new RuntimeException('AI_CONFIG_VERSION_CONFLICT');
        $key = $input['api_key'] ?? '';
        if (!is_string($key) || strlen($key) > 1024 || preg_match('/[\x00-\x20\x7f]/', $key)) throw new RuntimeException('AI_CONFIG_INVALID');
        if ($key === '') $key = $current['api_key'] ?? '';
        if ($key === '') throw new RuntimeException('AI_MODEL_KEY_REQUIRED');
        $nonce = random_bytes(12); $tag = '';
        $cipher = openssl_encrypt($key, 'aes-256-gcm', $this->private->signingKey(), OPENSSL_RAW_DATA, $nonce, $tag, $this->instance);
        if ($cipher === false) throw new RuntimeException('AI_CONFIG_INVALID');
        $values = [(int)$input['enabled'], $input['model'], base64_encode($nonce . $tag . $cipher), (int)$input['external_processing_authorized'], $current['version'] + 1, $this->instance];
        if ($current['version'] === 0) {
            $statement = $this->db->prepare('INSERT INTO ' . $this->table . ' (enabled,model,encrypted_key,external_authorized,version,instance_id) VALUES (?,?,?,?,?,?)');
        } else {
            $values[] = $current['version'];
            $statement = $this->db->prepare('UPDATE ' . $this->table . ' SET enabled=?,model=?,encrypted_key=?,external_authorized=?,version=? WHERE instance_id=? AND version=?');
        }
        $statement->execute($values);
        if ($statement->rowCount() !== 1) throw new RuntimeException('AI_CONFIG_VERSION_CONFLICT');
        return $this->read();
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
