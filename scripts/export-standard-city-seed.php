<?php
/** Local-only conversion of the project's bundled installer dictionary to
 * structured immutable rows. A connection-local temporary table protects all
 * existing local data; MySQL parses the original SQL instead of text splitting. */
require '/var/www/html/vendor/autoload.php';
$app = new think\App('/var/www/html/');
$app->env->load('/var/www/html/.env');$app->initialize();
$c = config('database.connections.mysql');
if (getenv('REGION_SEED_LOCAL_EXPORT') !== '1' || $c['hostname'] !== 'mysql' || $c['database'] !== 'ruihao') throw new RuntimeException('Local Docker only');
$sql = file_get_contents('/var/www/html/public/install/eb_city_area.sql');
if (!$sql || preg_match('/^(?:DROP|TRUNCATE|DELETE|UPDATE|USE|REPLACE|LOAD|RENAME)\b/im', $sql)) throw new RuntimeException('Unexpected installer statement');
preg_match_all('/^(?:CREATE TABLE IF NOT EXISTS|ALTER TABLE|INSERT INTO)\s+`([^`]+)`/m', $sql, $matches);
if (array_unique($matches[1]) !== ['eb_city_area']) throw new RuntimeException('Installer crosses dictionary boundary');
$sql = str_replace('CREATE TABLE IF NOT EXISTS `eb_city_area`', 'CREATE TEMPORARY TABLE `eb_city_area`', $sql, $count);
if ($count !== 1) throw new RuntimeException('Expected one dictionary table');
$pdo = new PDO('mysql:host=mysql;port=' . ($c['hostport'] ?: 3306) . ';dbname=ruihao;charset=utf8mb4', $c['username'], $c['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$before = hash('sha256', json_encode($pdo->query('SELECT * FROM eb_city_area ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
$pdo->exec($sql);
$ddl = $pdo->query('SHOW CREATE TABLE eb_city_area')->fetch(PDO::FETCH_ASSOC)['Create Table'];
if (strpos($ddl, 'CREATE TEMPORARY TABLE') !== 0) throw new RuntimeException('Expected isolated seed table');
$rows = $pdo->query('SELECT * FROM eb_city_area ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$pdo->exec('DROP TEMPORARY TABLE eb_city_area');
$after = hash('sha256', json_encode($pdo->query('SELECT * FROM eb_city_area ORDER BY id')->fetchAll(PDO::FETCH_ASSOC)));
if ($before !== $after) throw new RuntimeException('Persistent local dictionary changed');
echo json_encode(['source' => 'canonical bundled public/install/eb_city_area.sql', 'sourceSha256' => hash_file('sha256', '/var/www/html/public/install/eb_city_area.sql'), 'localDictionaryUnchanged' => true, 'rows' => $rows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
