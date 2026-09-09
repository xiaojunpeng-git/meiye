<?php
namespace app\services\ai\management;

use PDO;
use app\services\ai\registry\AiRegistryValue;

/** Durable non-secret configuration. No chat, model payload, key or business fact belongs here. */
final class AiManagementStore
{
    private $pdo;private $state;private $versions;private $instance;
    public function __construct(PDO $pdo,string $prefix,string $instance)
    {
        if(!preg_match('/^[a-zA-Z0-9_]*$/D',$prefix)||!preg_match('/^[a-zA-Z0-9_.:-]{1,128}$/D',$instance)) throw new \InvalidArgumentException('AI_MANAGEMENT_INSTANCE_INVALID');
        $this->pdo=$pdo;$this->state=$prefix.'mohe_ai_management_state';$this->versions=$prefix.'mohe_ai_management_version';$this->instance=$instance;
    }
    public function read(): array
    {
        $row=$this->row();$stmt=$this->pdo->prepare("SELECT version,created_at,action,source_version,document_hash FROM {$this->versions} WHERE instance_key=? ORDER BY created_at DESC,version DESC LIMIT 100");$stmt->execute([$this->instance]);
        return ['revision'=>(int)$row['revision'],'active_version'=>$row['active_version'],'draft'=>$this->decode($row['draft_json']),'versions'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
    public function active(): array
    {
        try {$row=$this->row();return $this->version($row['active_version']);}
        catch(\PDOException $e) {
            // Only definite missing-table errors permit the pre-install source baseline.
            $info=$e->errorInfo;
            if(($info[0]??'')==='42S02'||(($info[0]??'')==='HY000'&&($info[1]??0)===1&&strpos($e->getMessage(),'no such table:')!==false)) return $this->version('source');
            throw $e;
        }
    }
    public function version(string $id): array
    {
        if($id==='source') {$d=AiManagementPolicy::defaults();return ['version'=>'source','document'=>$d,'document_hash'=>AiRegistryValue::hash($d)];}
        if(!preg_match('/^v_[a-f0-9]{32}$/D',$id)) throw new \RuntimeException('AI_MANAGEMENT_VERSION_NOT_FOUND');
        $stmt=$this->pdo->prepare("SELECT version,document_json,document_hash FROM {$this->versions} WHERE instance_key=? AND version=?");$stmt->execute([$this->instance,$id]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new \RuntimeException('AI_MANAGEMENT_VERSION_NOT_FOUND');$d=$this->decode($row['document_json']);
        if(!hash_equals($row['document_hash'],AiRegistryValue::hash($d))) throw new \RuntimeException('AI_MANAGEMENT_DOCUMENT_CORRUPT');
        AiManagementPolicy::validate($d);return ['version'=>$row['version'],'document'=>$d,'document_hash'=>$row['document_hash']];
    }
    public function saveDraft(int $expectedRevision,array $document): array
    {
        $document=AiManagementPolicy::validate($document);$this->change($expectedRevision,$document,null,null);return $this->read();
    }
    public function validateDraft(int $expectedRevision): array
    {
        $row=$this->row();$this->expected($row,$expectedRevision);$d=AiManagementPolicy::validate($this->decode($row['draft_json']));AiManagementPolicy::applyManifest($d);
        return ['valid'=>true,'revision'=>(int)$row['revision'],'document_hash'=>AiRegistryValue::hash($d),'source_registry_hash'=>$d['source_registry_hash']];
    }
    public function publish(int $expectedRevision): array { $this->change($expectedRevision,null,'publish',null);return $this->read(); }
    public function rollback(int $expectedRevision,string $targetVersion): array { $this->change($expectedRevision,null,'rollback',$targetVersion);return $this->read(); }
    private function change(int $expectedRevision,?array $document,?string $action,?string $target): void
    {
        // Lock the single state row first; version insert and active pointer are one transaction.
        $this->row();$this->pdo->beginTransaction();
        try {
            $row=$this->row(true);$this->expected($row,$expectedRevision);
            if($document===null) $document=$target!==null?$this->version($target)['document']:$this->decode($row['draft_json']);
            $document=AiManagementPolicy::validate($document);AiManagementPolicy::applyManifest($document);$json=$this->encode($document);$active=$row['active_version'];
            if($action!==null) {
                $active='v_'.bin2hex(random_bytes(16));$stmt=$this->pdo->prepare("INSERT INTO {$this->versions}(instance_key,version,document_json,document_hash,created_at,action,source_version) VALUES(?,?,?,?,?,?,?)");
                $stmt->execute([$this->instance,$active,$json,AiRegistryValue::hash($document),time(),$action,$target??$row['active_version']]);
            }
            $stmt=$this->pdo->prepare("UPDATE {$this->state} SET draft_json=?,active_version=?,revision=revision+1,updated_at=? WHERE instance_key=? AND revision=?");$stmt->execute([$json,$active,time(),$this->instance,$expectedRevision]);
            if($stmt->rowCount()!==1) throw new \RuntimeException('AI_MANAGEMENT_REVISION_CONFLICT');$this->pdo->commit();
        } catch(\Throwable $e) {if($this->pdo->inTransaction()) $this->pdo->rollBack();throw $e;}
    }
    private function row(bool $lock=false): array
    {
        $suffix=$lock&&$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)!=='sqlite'?' FOR UPDATE':'';
        $stmt=$this->pdo->prepare("SELECT revision,active_version,draft_json FROM {$this->state} WHERE instance_key=?{$suffix}");$stmt->execute([$this->instance]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if($row) return $row;
        if(!$lock) {
            $insert=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'?'INSERT OR IGNORE':'INSERT IGNORE';
            $stmt=$this->pdo->prepare("{$insert} INTO {$this->state}(instance_key,revision,active_version,draft_json,updated_at) VALUES(?,1,'source',?,?)");$stmt->execute([$this->instance,$this->encode(AiManagementPolicy::defaults()),time()]);
        }
        $stmt=$this->pdo->prepare("SELECT revision,active_version,draft_json FROM {$this->state} WHERE instance_key=?{$suffix}");$stmt->execute([$this->instance]);$row=$stmt->fetch(PDO::FETCH_ASSOC);
        if(!$row) throw new \RuntimeException('AI_MANAGEMENT_NOT_READY');return $row;
    }
    private function expected(array $row,int $expected): void {if($expected<1||(int)$row['revision']!==$expected) throw new \RuntimeException('AI_MANAGEMENT_REVISION_CONFLICT');}
    private function decode(string $json): array {$value=json_decode($json,true);if(!is_array($value)||json_last_error()!==JSON_ERROR_NONE) throw new \RuntimeException('AI_MANAGEMENT_DOCUMENT_CORRUPT');return $value;}
    private function encode(array $value): string {$json=json_encode($value,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false) throw new \RuntimeException('AI_MANAGEMENT_DOCUMENT_INVALID');return $json;}
}
