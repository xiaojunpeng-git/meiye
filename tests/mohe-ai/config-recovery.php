<?php
// A local private-key rotation must be recoverable by explicitly replacing the
// model key; an omitted key still fails closed rather than silently changing it.
require_once __DIR__.'/fixture-autoload.php';
require_once __DIR__.'/../../后端代码/app/services/ai/config/AiPrivateStorage.php';
require_once __DIR__.'/../../后端代码/app/services/ai/config/AiConfigStore.php';

use app\services\ai\config\AiConfigStore;
use app\services\ai\config\AiPrivateStorage;

$checks = 0;
$check = static function ($value, string $label) use (&$checks): void {
    if (!$value) throw new RuntimeException('config recovery: '.$label);
    $checks++;
};
$expect = static function (callable $callback, string $reason) use ($check): void {
    try { $callback(); } catch (RuntimeException $exception) {
        $check($exception->getMessage() === $reason, 'expected '.$reason);
        return;
    }
    throw new RuntimeException('config recovery: missing '.$reason);
};

$root = sys_get_temp_dir().'/mohe-ai-config-recovery-'.bin2hex(random_bytes(8));
mkdir($root, 0700);
try {
    $db = new PDO('sqlite::memory:');
    $db->exec('CREATE TABLE mohe_ai_config (instance_id TEXT PRIMARY KEY, enabled INTEGER, model TEXT, encrypted_key TEXT, external_authorized INTEGER, external_scope_version TEXT, version INTEGER)');
    $storage = new AiPrivateStorage($root);
    $config = new AiConfigStore($db, '', 'fixture.config-recovery', $storage);
    $saved = $config->save(['enabled'=>true, 'external_processing_authorized'=>true,
        'external_scope_version'=>AiConfigStore::QUESTION_SCOPE, 'model'=>'fixture/model',
        'api_key'=>'first-fixture-key', 'version'=>0]);
    $check($saved['version'] === 1, 'initial encrypted configuration saves');

    unlink($root.'/master.key');
    $storage = new AiPrivateStorage($root);
    $config = new AiConfigStore($db, '', 'fixture.config-recovery', $storage);
    $expect(static function () use ($config): void { $config->read(true); }, 'AI_MODEL_CONFIG_INVALID');
    $expect(static function () use ($config): void {
        $config->save(['enabled'=>true, 'external_processing_authorized'=>true,
            'external_scope_version'=>AiConfigStore::QUESTION_SCOPE, 'model'=>'fixture/model',
            'api_key'=>'', 'version'=>1]);
    }, 'AI_MODEL_CONFIG_INVALID');

    $recovered = $config->save(['enabled'=>true, 'external_processing_authorized'=>true,
        'external_scope_version'=>AiConfigStore::QUESTION_SCOPE, 'model'=>'fixture/model',
        'api_key'=>'replacement-fixture-key', 'version'=>1]);
    $check($recovered['version'] === 2 && $config->read(true)['api_key'] === 'replacement-fixture-key',
        'an explicit replacement key repairs a rotated private key without decrypting the obsolete ciphertext');
    // Provider migration is a new external-processing decision. A legacy key
    // and its consent must be inert until this instance explicitly saves a
    // replacement key and authorizations for the new destination.
    $openlux = new AiConfigStore($db, '', 'fixture.config-recovery', $storage, 'openlux');
    $previous = $openlux->read();
    $check($previous['provider'] === 'openlux' && $previous['model'] === '' && !$previous['enabled'] && !$previous['has_api_key']
        && !$previous['external_processing_authorized'] && $previous['external_scope_version'] === '',
        'provider switch invalidates legacy key and both consent scopes');
    $expect(static function () use ($openlux): void { $openlux->read(true); }, 'AI_MODEL_CONFIG_INVALID');
    $switched = $openlux->save(['enabled'=>true, 'external_processing_authorized'=>true,
        'external_scope_version'=>AiConfigStore::QUESTION_SCOPE, 'model'=>'fixture/new-model',
        'api_key'=>'openlux-fixture-key', 'version'=>2]);
    $check($switched['version'] === 3 && $openlux->read(true)['api_key'] === 'openlux-fixture-key'
        && AiConfigStore::allowsSanitizedQuestion($switched), 'new provider key and explicit consent activate together');
    $check(!$config->read()['has_api_key'] && !$config->read()['enabled'], 'old provider cannot reuse replacement key');
    echo 'Config recovery: '.$checks." checks PASS\n";
} finally {
    if (is_file($root.'/master.key')) unlink($root.'/master.key');
    rmdir($root);
}
