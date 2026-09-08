<?php
namespace app\services\ai\execution;

use app\services\ai\config\AiConfigStore;
use app\services\ai\config\AiPrivateStorage;
use app\services\query\metric\MetricReadViewStore;

/** HTTP, supervisor and dedicated export worker share the same trusted instance wiring. */
final class AiRuntimeFactory
{
    public static function make(): array
    {
        $connection=(string)config('database.default','mysql'); $db=(array)config('database.connections.'.$connection,[]);
        if (($db['type']??'mysql')!=='mysql' || empty($db['database'])) throw new \RuntimeException('AI_INSTALLATION_REQUIRED');
        $pdo=new \PDO('mysql:host='.($db['hostname']??'127.0.0.1').';port='.($db['hostport']??3306).';dbname='.$db['database'].';charset=utf8mb4',
            $db['username']??'', $db['password']??'', [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_TIMEOUT=>3,\PDO::ATTR_EMULATE_PREPARES=>false]);
        $server=$pdo->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS schema_name')->fetch(\PDO::FETCH_ASSOC);
        if (empty($server['server_uuid']) || ($server['schema_name']??'')!==$db['database']) throw new \RuntimeException('AI_INSTANCE_BINDING_INVALID');
        $configured=(string)config('mohe_ai.instance_id','');
        if ($configured!=='' && !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$configured)) throw new \RuntimeException('AI_INSTANCE_BINDING_INVALID');
        $identity=hash('sha256',json_encode([$configured,$server['server_uuid'],$server['schema_name']]));
        $instance='instance-'.$identity;
        $root=rtrim(app()->getRuntimePath(),DIRECTORY_SEPARATOR).'/private/mohe-ai/'.$identity;
        $private=new AiPrivateStorage($root);
        $prefix=(string)($db['prefix']??'');
        return ['instance'=>$instance,'private'=>$private,'runs'=>new AiRunStore($pdo,$prefix,$instance),
            'config'=>new AiConfigStore($pdo,$prefix,$instance,$private),'views'=>new MetricReadViewStore($root.'/report-views',$private->signingKey())];
    }
}
