<?php
declare(strict_types=1);

/**
 * 从 010 自身的迁移前 SQL 备份恢复丢失的来源 ID 审计行。
 * 这行仅证明 007 的来源 ID 与 010 新字典 ID 的既有对应关系；不改订单、收款或字典。
 */
function restore010Source007MappingAudit(PDO $db, string $backupPath, string $mode): array
{
    if (PHP_SAPI !== 'cli' || !in_array($mode, ['inspect', 'apply'], true)
        || (string)$db->query('SELECT DATABASE()')->fetchColumn() !== 'mohe_010_app'
        || (int)$db->query('SELECT @@port')->fetchColumn() !== 3307) {
        throw new RuntimeException('010_TARGET_AND_MODE_REQUIRED');
    }
    $row = parse010Source007MappingAuditBackup($backupPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->beginTransaction();
    try {
        $lock = $mode === 'apply' ? ' FOR UPDATE' : '';
        $existing = $db->query('SELECT id FROM codex_010_source007_audit WHERE id=1' . $lock)->fetch(PDO::FETCH_ASSOC);
        if ($existing !== false) throw new RuntimeException('AUDIT_ALREADY_PRESENT');
        $actual = $db->query('SELECT id,name FROM eb_cashier_v3_business_source WHERE id BETWEEN 18 AND 29 ORDER BY id' . $lock)
            ->fetchAll(PDO::FETCH_ASSOC);
        if (count($actual) !== 12) throw new RuntimeException('TARGET_DICTIONARY_CHANGED');
        foreach ($row['mapping'] as $index => $source) {
            if ((int)$actual[$index]['id'] !== (int)$source['target010Id']
                || $actual[$index]['name'] !== $source['name']) {
                throw new RuntimeException('TARGET_DICTIONARY_CHANGED');
            }
        }
        if ($mode === 'apply') {
            $insert = $db->prepare('INSERT INTO codex_010_source007_audit (id,before_json,mapping_json,applied_at) VALUES (1,?,?,?)');
            $insert->execute([$row['before_json'], $row['mapping_json'], $row['applied_at']]);
            $saved = $db->query('SELECT before_json,mapping_json,applied_at FROM codex_010_source007_audit WHERE id=1')
                ->fetch(PDO::FETCH_ASSOC);
            if (!$saved || $saved['before_json'] !== $row['before_json']
                || $saved['mapping_json'] !== $row['mapping_json']
                || (int)$saved['applied_at'] !== $row['applied_at']) {
                throw new RuntimeException('AUDIT_RESTORE_VERIFY_FAILED');
            }
            $db->commit();
        } else {
            $db->rollBack();
        }
        return ['mode' => $mode, 'source_rows' => count($row['mapping']),
            'backup_line_sha256' => $row['line_sha256'], 'applied_at' => $row['applied_at']];
    } catch (Throwable $error) {
        if ($db->inTransaction()) $db->rollBack();
        throw $error;
    }
}

/** 只接受原始 SQL 备份中的唯一一行和固定指纹，避免按现有名称臆造历史映射。 */
function parse010Source007MappingAuditBackup(string $backupPath): array
{
    if (!is_file($backupPath) || !is_readable($backupPath)) throw new RuntimeException('BACKUP_UNAVAILABLE');
    $stream = gzopen($backupPath, 'rb');
    if ($stream === false) throw new RuntimeException('BACKUP_UNAVAILABLE');
    $line = null;
    try {
        while (!gzeof($stream)) {
            $candidate = gzgets($stream);
            if ($candidate === false) break;
            if (strpos($candidate, 'INSERT INTO `codex_010_source007_audit` VALUES ') === 0) {
                if ($line !== null) throw new RuntimeException('BACKUP_AUDIT_NOT_UNIQUE');
                $line = $candidate;
            }
        }
    } finally {
        gzclose($stream);
    }
    if ($line === null || !hash_equals('10adee64e4d38fbce607312e4fc6b019cea0b41ffcd39feac0b02d9b770a43ed', hash('sha256', $line))) {
        throw new RuntimeException('BACKUP_AUDIT_FINGERPRINT_CHANGED');
    }
    $pattern = <<<'REGEX'
~\AINSERT INTO `codex_010_source007_audit` VALUES \(1,'((?:\\.|[^'\\])*)','((?:\\.|[^'\\])*)',([0-9]+)\);\r?\n?\z~D
REGEX;
    if (preg_match($pattern, $line, $match) !== 1) throw new RuntimeException('BACKUP_AUDIT_FORMAT_CHANGED');
    $beforeJson = stripcslashes($match[1]);
    $mappingJson = stripcslashes($match[2]);
    $before = json_decode($beforeJson, true, 512, JSON_THROW_ON_ERROR);
    $mapping = json_decode($mappingJson, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($before) || count($before) !== 17 || !is_array($mapping) || count($mapping) !== 12) {
        throw new RuntimeException('BACKUP_AUDIT_ROW_COUNT_CHANGED');
    }
    foreach ($mapping as $index => $source) {
        if ((int)($source['source007Id'] ?? 0) !== $index + 1
            || (int)($source['target010Id'] ?? 0) !== $index + 18
            || trim((string)($source['name'] ?? '')) === '') {
            throw new RuntimeException('BACKUP_AUDIT_MAPPING_CHANGED');
        }
    }
    return ['before_json' => $beforeJson, 'mapping_json' => $mappingJson,
        'mapping' => $mapping, 'applied_at' => (int)$match[3], 'line_sha256' => hash('sha256', $line)];
}
