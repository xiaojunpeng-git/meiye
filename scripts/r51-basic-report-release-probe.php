<?php

declare(strict_types=1);

/** Read-only release inventory, executed from SSH stdin. Never boot ThinkPHP,
 * load application classes, call an installer or emit configuration contents.
 * Only fingerprints, schema and explicitly allowlisted navigation metadata leave
 * the instance. No mutation mode exists. Compatible with production PHP 7.4. */
ini_set('display_errors', '0');
ini_set('log_errors', '0');
set_error_handler(static function (): void { throw new RuntimeException('PROBE_IO_FAILURE'); });

function r51ProbeHash(string $file): ?array
{
    if (!is_file($file)) return null;
    clearstatcache(true, $file);
    $before = stat($file);
    $sha = hash_file('sha256', $file);
    clearstatcache(true, $file);
    $after = stat($file);
    if ($before['size'] !== $after['size'] || $before['mtime'] !== $after['mtime'] || $before['ino'] !== $after['ino']) {
        throw new RuntimeException('FILE_CHANGED_DURING_PROBE');
    }
    return ['sha256' => $sha, 'bytes' => $after['size'], 'mtime' => $after['mtime']];
}

/** Full PHP tree, not a guessed direct-dependency list. Symlinks outside the
 * deployed tree are reported rather than following other customer instances. */
function r51ProbeAllowedPath(string $path, array $allowedBases): bool
{
    foreach ($allowedBases as $base) {
        $real = realpath($base);
        if ($real !== false && ($path === $real || strpos($path, $real . '/') === 0)) return true;
    }
    return false;
}

function r51ProbeTree(string $base, array $directories, array $extensions, array $allowedBases = []): array
{
    $files = []; $gaps = []; $links = [];
    foreach ($directories as $directory) {
        $root = $base . '/' . $directory;
        if (!is_dir($root)) { $gaps[] = ['path' => $directory, 'reason' => 'directory_missing']; continue; }
        if (is_link($root)) {
            $target = realpath($root);
            if ($target === false || !r51ProbeAllowedPath($target, $allowedBases)) {
                $gaps[] = ['path' => $directory, 'reason' => 'symlink_directory_not_allowlisted']; continue;
            }
            $links[] = ['path' => $directory, 'target' => $target];
        }
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            $relative = substr($file->getPathname(), strlen($base) + 1);
            if ($file->isLink()) { $gaps[] = ['path' => $relative, 'reason' => 'symlink_not_followed']; continue; }
            if (!$file->isFile() || !in_array(strtolower($file->getExtension()), $extensions, true)) continue;
            $files[$relative] = r51ProbeHash($file->getPathname());
        }
    }
    ksort($files, SORT_STRING);
    return ['files' => $files, 'file_count' => count($files),
        'manifest_sha256' => hash('sha256', json_encode($files)), 'gaps' => $gaps, 'same_instance_symlink_roots' => $links];
}

/** Normalize static resource paths within the public root. URLs are not
 * executed remotely; references outside this origin/root are only identified. */
function r51ProbeAssetPath(string $url, string $entry, string $domain): ?string
{
    $parts = parse_url(html_entity_decode($url, ENT_QUOTES, 'UTF-8'));
    if ($parts === false || isset($parts['user']) || isset($parts['pass'])) return null;
    if (isset($parts['host']) && $parts['host'] !== $domain) return null;
    if (isset($parts['scheme']) && !in_array($parts['scheme'], ['http', 'https'], true)) return null;
    $path = $parts['path'] ?? '';
    $path = preg_replace('~^/preview-8080/~', '/', $path);
    if ($path === '' || strpos($path, "\0") !== false) return null;
    if ($path[0] !== '/') $path = '/' . dirname($entry) . '/' . $path;
    $normalized = [];
    foreach (explode('/', $path) as $part) {
        if ($part === '' || $part === '.') continue;
        if ($part === '..') { if (!$normalized) return null; array_pop($normalized); }
        else $normalized[] = $part;
    }
    return implode('/', $normalized);
}

function r51ProbeFrontend(string $root, string $domain, bool $preview, string $kind): array
{
    $frontRoot = $root . ($preview ? '-8080' : '');
    $allowedPublicRoots = [$root . '/public'];
    if ($preview) $allowedPublicRoots[] = $root . '-8080/public';
    $entry = $kind === 'cashier' ? 'view_cashier_v3/index.html' : 'system.html';
    $urlPath = ($preview ? '/preview-8080' : '') . ($kind === 'cashier' ? '/view_cashier_v3/' : '/system.html');
    $result = ['kind' => $kind, 'preview' => $preview, 'root' => $frontRoot, 'entry' => 'public/' . $entry,
        'url' => 'https://' . $domain . $urlPath, 'entry_fingerprint' => r51ProbeHash($frontRoot . '/public/' . $entry),
        'references' => [], 'asset_trees' => []];
    if ($result['entry_fingerprint'] === null) return $result;
    $html = file_get_contents($frontRoot . '/public/' . $entry);
    // Production minification removes attribute quotes; include those tags as
    // well as quoted preload/prefetch references, or platform bundles vanish.
    preg_match_all('~(?:src|href)\s*=\s*["\']?([^"\'\s>]+)~i', $html, $matches);
    $directories = [];
    foreach ($matches[1] as $url) {
        $path = r51ProbeAssetPath($url, $entry, $domain);
        if ($path === null || !preg_match('~\.(js|css)$~i', $path)) continue;
        $full = $frontRoot . '/public/' . $path;
        $real = realpath($full);
        if ($real !== false && !r51ProbeAllowedPath($real, $allowedPublicRoots)) {
            $result['references'][$path] = ['error' => 'outside_public_root']; continue;
        }
        $result['references'][$path] = r51ProbeHash($full);
        $first = explode('/', $path)[0];
        if (strpos($path, '/') !== false) $directories[$first] = $first;
    }
    if ($kind === 'cashier') $directories['view_cashier_v3'] = 'view_cashier_v3';
    // Include every deployed JS/CSS chunk in the referenced directory trees,
    // covering lazy chunks as a superset, not only entrypoint script tags.
    if ($directories) $result['asset_trees'] = r51ProbeTree($frontRoot . '/public', array_values($directories), ['js', 'css'], $allowedPublicRoots);
    ksort($result['references'], SORT_STRING);
    return $result;
}

/** Schema only: no audit payloads, account rows, role grants or business rows. */
function r51ProbeSchema(PDO $db, string $table): array
{
    $query = $db->prepare('SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $query->execute([$table]);
    $identity = $query->fetch();
    if (!$identity) return ['exists' => false];
    $query = $db->prepare('SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,EXTRA,ORDINAL_POSITION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
    $query->execute([$table]);
    $columns = $query->fetchAll();
    $query = $db->prepare('SELECT INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY INDEX_NAME,SEQ_IN_INDEX');
    $query->execute([$table]);
    return ['exists' => true, 'table' => $identity, 'columns' => $columns, 'indexes' => $query->fetchAll()];
}

function r51ProbeDatabase(array $cfg, string $database, int $port): array
{
    $db = new PDO('mysql:host=' . $cfg['hostname'] . ';port=' . $port . ';dbname=' . $database . ';charset=utf8mb4',
        $cfg['username'], $cfg['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::ATTR_TIMEOUT => 12]);
    $db->exec('SET SESSION TRANSACTION READ ONLY');
    $db->exec('SET TRANSACTION READ ONLY');
    $db->beginTransaction();
    try {
        $identity = $db->query('SELECT DATABASE() database_name,@@port server_port,@@hostname server_hostname,VERSION() server_version,@@session.tx_read_only session_read_only')->fetch();
        if ($identity['database_name'] !== $database || (int)$identity['server_port'] !== $port || (int)$identity['session_read_only'] !== 1) {
            throw new RuntimeException('CONNECTED_DATABASE_IDENTITY_MISMATCH');
        }
        $out = ['identity' => $identity, 'transaction_read_only' => true, 'schemas' => [], 'migration_tables' => [], 'recent_upgrades' => [], 'permission_catalog' => null];
        $migrationTables = $db->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND (TABLE_NAME LIKE '%migration%' OR TABLE_NAME LIKE '%upgrade_log%' OR TABLE_NAME LIKE '%phinx%') ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
        $out['migration_tables'] = $migrationTables;
        $tables = array_unique(array_merge($migrationTables, ['eb_database_upgrade_log', 'eb_basic_report_registration_audit',
            'eb_store_v3_permission_catalog', 'eb_store_v3_permission_audit', 'eb_system_menus', 'eb_salary_store']));
        foreach ($tables as $table) $out['schemas'][$table] = r51ProbeSchema($db, $table);
        if ($out['schemas']['eb_database_upgrade_log']['exists']) {
            $available = array_column($out['schemas']['eb_database_upgrade_log']['columns'], 'COLUMN_NAME');
            $fields = array_values(array_intersect(['id', 'upgrade_key', 'code_version', 'sql_checksum', 'executed_at'], $available));
            if ($fields) {
                $order = in_array('id', $available, true) ? 'id' : (in_array('executed_at', $available, true) ? 'executed_at' : $fields[0]);
                $out['recent_upgrades'] = $db->query('SELECT `' . implode('`,`', $fields) . '` FROM eb_database_upgrade_log ORDER BY `' . $order . '` DESC LIMIT 20')->fetchAll();
            }
        }
        if ($out['schemas']['eb_store_v3_permission_catalog']['exists']) {
            $row = $db->query('SELECT id,revision,updated_at,rules_json FROM eb_store_v3_permission_catalog WHERE id=1')->fetch();
            if ($row) {
                $decoded = json_decode($row['rules_json'], true);
                $nodes = [];
                if (is_array($decoded)) foreach ($decoded as $node) {
                    if (!is_array($node)) continue;
                    // Only global navigation structure, never access scopes,
                    // user grants, account-specific rules or audit before/after.
                    $nodes[] = array_intersect_key($node, array_flip(['id', 'parent_id', 'title', 'navigation_key', 'owner_feature', 'sort']));
                }
                usort($nodes, static function ($a, $b) { return ($a['id'] ?? 0) <=> ($b['id'] ?? 0); });
                $out['permission_catalog'] = ['id' => (int)$row['id'], 'revision' => (int)$row['revision'],
                    'updated_at' => (int)$row['updated_at'], 'rules_sha256' => hash('sha256', $row['rules_json']),
                    'json_valid' => is_array($decoded), 'navigation_nodes' => $nodes];
            }
        }
        if ($out['schemas']['eb_system_menus']['exists']) {
            $out['report_platform_navigation'] = $db->query("SELECT id,pid,type,menu_name,menu_path,unique_auth,sort,is_del FROM eb_system_menus WHERE menu_path LIKE '%report%' OR menu_path='/admin/setting/store-permissions' ORDER BY id")->fetchAll();
        }
        return $out;
    } finally {
        if ($db->inTransaction()) $db->rollBack();
    }
}

try {
    $site = $argv[1] ?? '';
    $sites = ['rh' => ['rh.cc3798.com', 'ruihao', 3306], '008' => ['008.cc3798.com', 'mohe_008', 3307], '010' => ['010.cc3798.com', 'mohe_010_app', 3307]];
    if (!isset($sites[$site]) || count($argv) !== 2) throw new RuntimeException('EXPLICIT_INSTANCE_REQUIRED');
    [$domain, $database, $port] = $sites[$site];
    $root = '/www/wwwroot/' . $domain;
    if (realpath($root) !== $root) throw new RuntimeException('INSTANCE_ROOT_MISMATCH');
    $protected = [$root . '/.env', '/www/server/panel/vhost/nginx/' . $domain . '.conf',
        $root . '/.htaccess', $root . '/.user.ini'];
    $before = [];
    foreach ($protected as $file) $before[$file] = r51ProbeHash($file);
    $ini = parse_ini_file($root . '/.env', true, INI_SCANNER_RAW);
    $sections = array_change_key_case($ini, CASE_LOWER);
    $cfg = array_change_key_case($sections['database'] ?? [], CASE_LOWER);
    if (($cfg['database'] ?? '') !== $database || (int)($cfg['hostport'] ?? 0) !== $port
        || ($cfg['prefix'] ?? '') !== 'eb_' || !in_array($cfg['hostname'] ?? '', ['localhost', '127.0.0.1'], true)) {
        throw new RuntimeException('INSTANCE_DATABASE_CONFIG_MISMATCH');
    }
    $out = ['site' => $site, 'domain' => $domain, 'root' => $root, 'started_at' => gmdate('c'),
        'source_text_returned' => false, 'writes_performed' => false, 'application_booted' => false,
        'backend' => r51ProbeTree($root, ['app', 'route', 'config'], ['php']), 'frontends' => []];
    foreach ([false, true] as $preview) {
        if ($preview && $site !== 'rh') continue;
        foreach (['cashier', 'platform'] as $kind) $out['frontends'][] = r51ProbeFrontend($root, $domain, $preview, $kind);
    }
    $out['database'] = r51ProbeDatabase($cfg, $database, $port);
    $out['protected_fingerprints'] = $before;
    foreach ($protected as $file) {
        if ($before[$file] !== r51ProbeHash($file)) throw new RuntimeException('PROTECTED_FILE_CHANGED_DURING_PROBE');
    }
    $out['protected_unchanged_during_probe'] = true;
    $out['finished_at'] = gmdate('c');
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
} catch (Throwable $exception) {
    // Never return PDO messages, stack traces or parser warnings: they may
    // contain database users, credentials or private environment fragments.
    $safe = $exception instanceof RuntimeException && preg_match('/^[A-Z][A-Z_]+$/D', $exception->getMessage())
        ? $exception->getMessage() : 'READ_ONLY_PROBE_FAILED';
    echo json_encode(['error_code' => $safe, 'writes_performed' => false, 'application_booted' => false]), "\n";
    exit(1);
}
