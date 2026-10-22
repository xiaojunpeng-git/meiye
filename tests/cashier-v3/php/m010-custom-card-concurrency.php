<?php
declare(strict_types=1);

/** Dedicated empty Docker database only; never runs against the shared local or a customer database. */
require '/var/www/html/vendor/autoload.php';
use think\facade\Db;
use app\services\cashier\v3\card\CashierV3CustomCardTemplateServices;

(new think\App('/var/www/html/'))->initialize();
if (getenv('M010_ISOLATED_DB') !== '1') throw new RuntimeException('Dedicated Docker database required');
$database = config('database');
$database['connections']['mysql'] = array_replace($database['connections']['mysql'], [
    'hostname' => 'mysql', 'hostport' => '3306', 'database' => 'm010_custom_card', 'username' => 'root', 'password' => '',
]);
app()->config->set($database, 'database');
if (Db::query('SELECT DATABASE() db,VERSION() version')[0]['db'] !== 'm010_custom_card') throw new RuntimeException('Wrong test database');
set_exception_handler(static function (Throwable $e): void {
    file_put_contents('php://stderr', $e->getMessage() . "\n"); exit(1);
});
$templates = new CashierV3CustomCardTemplateServices();
if (($argv[1] ?? '') === 'worker') {
    $store = (int)($argv[2] ?? 0);
    Db::startTrans();
    try {
        // Both workers deliberately take a consistent snapshot before either
        // carrier exists. ensureInTx must re-read after the parent lock.
        $templates->find($store);
        echo "READY\n"; flush();
        if (trim((string)fgets(STDIN)) !== 'GO') throw new RuntimeException('Missing worker barrier');
        $identity = $templates->ensureInTx($store);
        Db::commit();
        echo json_encode($identity) . "\n";
    } catch (Throwable $e) { Db::rollback(); throw $e; }
    exit;
}

$tag = 'M010-CONCURRENT-' . bin2hex(random_bytes(6));
$store = (int)Db::name('system_store')->insertGetId(['name' => $tag, 'is_del' => 0]);
$otherStore = (int)Db::name('system_store')->insertGetId(['name' => $tag . '-other', 'is_del' => 0]);
$workers = [];
try {
    for ($i = 0; $i < 2; $i++) {
        $process = proc_open([PHP_BINARY, __FILE__, 'worker', (string)$store],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        if (!is_resource($process)) throw new RuntimeException('Cannot launch worker');
        foreach ([0, 1, 2] as $fd) stream_set_timeout($pipes[$fd], 20);
        $workers[] = ['process' => $process, 'pipes' => $pipes];
        if (trim((string)fgets($pipes[1])) !== 'READY') throw new RuntimeException('Worker did not reach barrier');
    }
    foreach ($workers as $worker) { fwrite($worker['pipes'][0], "GO\n"); fclose($worker['pipes'][0]); }
    $results = [];
    foreach ($workers as $worker) {
        $result = json_decode((string)fgets($worker['pipes'][1]), true);
        $error = stream_get_contents($worker['pipes'][2]);
        fclose($worker['pipes'][1]); fclose($worker['pipes'][2]);
        if (proc_close($worker['process']) !== 0 || !is_array($result)) throw new RuntimeException('Worker failed: ' . $error);
        $results[] = $result;
    }
    $workers = [];
    if ($results[0] !== $results[1]
        || Db::name('store_product')->where('relation_id', $store)->where('keyword', $templates::marker($store))->count() !== 1
        || Db::name('store_product_attr_value')->where('product_id', $results[0]['productId'])->count() !== 1) {
        throw new RuntimeException('Concurrent first purchases duplicated carriers');
    }
    echo "PASS two stale snapshots create exactly one product and one SKU\n";
    Db::startTrans();
    try {
        $other = $templates->ensureInTx($otherStore);
        if ($other === $results[0]) throw new RuntimeException('Cross-store carrier reused');
        echo "PASS different stores never share a carrier\n";
    } finally { Db::rollback(); }
    if ($templates->find($otherStore) !== null) throw new RuntimeException('New carrier survived failed transaction');
    echo "PASS rollback removes a newly created carrier and SKU\n";
} finally {
    foreach ($workers as $worker) {
        proc_terminate($worker['process']);
        foreach ($worker['pipes'] as $pipe) if (is_resource($pipe)) fclose($pipe);
        proc_close($worker['process']);
    }
    // Only the dedicated synthetic stores from this test may be cleaned up.
    foreach ([$store, $otherStore] as $id) {
        $products = Db::name('store_product')->where('relation_id', $id)->where('keyword', $templates::marker($id))->column('id');
        if ($products) {
            Db::name('store_product_attr_value')->whereIn('product_id', $products)->delete();
            Db::name('store_product')->whereIn('id', $products)->delete();
        }
        Db::name('system_store')->where('id', $id)->where('name', 'like', $tag . '%')->delete();
    }
}
echo "M010 CONCURRENCY PASS 3\n";
