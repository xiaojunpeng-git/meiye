<?php
declare(strict_types=1);

/** Full Excel evidence may be larger than a screen answer; request/answer caps stay tight. */
require __DIR__.'/../../后端代码/app/services/ai/config/AiPrivateStorage.php';

use app\services\ai\config\AiPrivateStorage;

$directory=sys_get_temp_dir().'/r57-evidence-'.bin2hex(random_bytes(8));
$storage=new AiPrivateStorage($directory);
$expires=time()+60;
try {
    $data=['rows'=>str_repeat('a',2*1048576)];
    $ref=$storage->put('evidence',$data,$expires);
    if ($storage->read($ref)!==$data) throw new RuntimeException('EVIDENCE_ROUNDTRIP_FAILED');
    try {
        $storage->put('answer',$data,$expires);
        throw new RuntimeException('ANSWER_CAP_BYPASSED');
    } catch (RuntimeException $error) {
        if ($error->getMessage()!=='AI_PRIVATE_OBJECT_INVALID') throw $error;
    }
    try {
        $storage->put('evidence',['rows'=>str_repeat('a',4*1048576)],$expires);
        throw new RuntimeException('EVIDENCE_CAP_BYPASSED');
    } catch (RuntimeException $error) {
        if ($error->getMessage()!=='AI_PRIVATE_OBJECT_INVALID') throw $error;
    }
    echo "R57_PRIVATE_EVIDENCE_PASS\n";
} finally {
    if (isset($ref)) $storage->discard($ref);
    $key=$directory.'/master.key';
    if (is_file($key)) unlink($key);
    if (is_dir($directory)) rmdir($directory);
}
