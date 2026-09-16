<?php
namespace app\services\ai\execution;

use app\services\ai\config\AiConfigStore;
use app\services\ai\config\AiPrivateStorage;
use app\services\query\metric\MetricReadViewStore;

/** HTTP, supervisor and dedicated export worker share the same trusted instance wiring. */
final class AiRuntimeFactory
{
    /**
     * Swoole keeps HTTP workers alive.  Creating an additional direct PDO for
     * every status poll therefore leaks sleeping MySQL sessions until the
     * server reaches max_connections and an otherwise healthy Run starts
     * returning intermittent transport failures.  Cache only the verified
     * connection per process/configuration; request-scoped stores remain new
     * objects, so no Run, owner, prompt, or result state is shared.
     *
     * A broken server connection is discarded and rebuilt below.  The cache
     * key includes the connection identity without retaining its cleartext
     * password, which keeps a later per-process configuration change from
     * silently reusing another instance's database connection.
     *
     * @var array<string,array{pdo:\PDO,server:array{server_uuid:string,schema_name:string}}>
     */
    private static array $connections=[];

    public static function make(): array
    {
        $connection=(string)config('database.default','mysql'); $db=(array)config('database.connections.'.$connection,[]);
        if (($db['type']??'mysql')!=='mysql' || empty($db['database'])) throw new \RuntimeException('AI_INSTALLATION_REQUIRED');
        $shared=self::connection($connection,$db);
        $pdo=$shared['pdo']; $server=$shared['server'];
        $configured=(string)config('mohe_ai.instance_id','');
        if ($configured!=='' && !preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$configured)) throw new \RuntimeException('AI_INSTANCE_BINDING_INVALID');
        $identity=hash('sha256',json_encode([$configured,$server['server_uuid'],$server['schema_name']]));
        $instance='instance-'.$identity;
        $root=rtrim(app()->getRuntimePath(),DIRECTORY_SEPARATOR).'/private/mohe-ai/'.$identity;
        $private=new AiPrivateStorage($root);
        $prefix=(string)($db['prefix']??'');
        return ['instance'=>$instance,'private'=>$private,'runs'=>new AiRunStore($pdo,$prefix,$instance),
            'config'=>new AiConfigStore($pdo,$prefix,$instance,$private),'views'=>new MetricReadViewStore($root.'/report-views',$private->signingKey()),
            'management'=>new \app\services\ai\management\AiManagementStore($pdo,$prefix,$instance)];
    }

    /** @return array{pdo:\PDO,server:array{server_uuid:string,schema_name:string}} */
    private static function connection(string $name,array $db): array
    {
        $fingerprint=hash('sha256',json_encode([
            $name,$db['hostname']??'127.0.0.1',$db['hostport']??3306,$db['database']??'',
            $db['username']??'',hash('sha256',(string)($db['password']??'')),
        ]));
        if (isset(self::$connections[$fingerprint])) {
            try {
                // This cheap liveness probe also detects a database restart;
                // never retain a stale PDO merely because this is a worker.
                self::$connections[$fingerprint]['pdo']->query('SELECT 1');
                return self::$connections[$fingerprint];
            } catch (\Throwable $ignored) {
                unset(self::$connections[$fingerprint]);
            }
        }
        $pdo=new \PDO('mysql:host='.($db['hostname']??'127.0.0.1').';port='.($db['hostport']??3306).';dbname='.$db['database'].';charset=utf8mb4',
            $db['username']??'', $db['password']??'', [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION,\PDO::ATTR_TIMEOUT=>3,\PDO::ATTR_EMULATE_PREPARES=>false]);
        $server=$pdo->query('SELECT @@server_uuid AS server_uuid, DATABASE() AS schema_name')->fetch(\PDO::FETCH_ASSOC);
        if (!is_array($server) || empty($server['server_uuid']) || ($server['schema_name']??'')!==$db['database']) {
            throw new \RuntimeException('AI_INSTANCE_BINDING_INVALID');
        }
        return self::$connections[$fingerprint]=['pdo'=>$pdo,'server'=>['server_uuid'=>(string)$server['server_uuid'],'schema_name'=>(string)$server['schema_name']]];
    }
}
