<?php
declare(strict_types=1);

/** Controlled data repair, not an application endpoint. Only a pristine empty
 * dictionary and three reviewed zero-code stores may change. No address text,
 * street, visibility, customer entitlement or historical fact is rewritten. */
final class Member008RegionDataRepair
{
    private static function require(bool $ok, string $message): void
    {
        if (!$ok) throw new RuntimeException($message);
    }

    /** PDO may return integers or numeric strings across drivers. Canonical
     * scalar strings preserve values while making audit comparisons portable. */
    public static function digest(array $rows): string
    {
        foreach ($rows as &$row) {
            ksort($row);
            foreach ($row as &$value) if ($value !== null) $value = (string)$value;
            unset($value);
        }
        unset($row);
        return hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** One transaction locks dictionary gaps and the three stable store IDs.
     * Caller must persist a private rollback snapshot before the first write.
     * dryRun executes the same DML but always rolls it back. */
    public static function apply(PDO $pdo, array $plan, array $seed, callable $backup, bool $dryRun = false): array
    {
        self::require(count($plan['changes']) === 3 && count($seed['rows']) === $plan['seedRows'], 'Wrong fixed plan');
        $ids = array_map(static fn($c) => (int)$c['before']['id'], $plan['changes']);
        self::require($ids === [127, 129, 130], 'Unreviewed store IDs');
        $nodes = [];
        foreach ($seed['rows'] as $row) {
            self::require(!isset($nodes[(int)$row['id']]), 'Duplicate dictionary ID');
            $nodes[(int)$row['id']] = $row;
        }
        foreach ($plan['changes'] as $change) {
            $parent = 0;
            foreach (['province', 'city', 'area'] as $i => $field) {
                $node = $nodes[(int)$change['after'][$field]] ?? null;
                self::require($node && $node['name'] === $change['names'][$i] && (int)$node['parent_id'] === $parent && (int)$node['level'] === $i + 1, 'Invalid region chain');
                $parent = (int)$node['id'];
            }
        }
        unset($nodes);
        $pdo->beginTransaction();
        try {
            $currentDictionary = $pdo->query('SELECT * FROM eb_city_area ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            $stores = $pdo->query('SELECT * FROM eb_system_store WHERE id IN (127,129,130) ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            self::require(count($stores) === 3, 'Store disappeared');
            $expected = $stores;
            $already = true;
            foreach ($plan['changes'] as $i => $change) {
                foreach ($change['after'] as $field => $value) if ((int)$stores[$i][$field] !== $value) $already = false;
                foreach ($change['after'] as $field => $value) $expected[$i][$field] = $value;
            }
            if ($currentDictionary) {
                self::require($already && self::digest($currentDictionary) === self::digest($seed['rows']), 'Nonempty or independently changed dictionary');
                // Idempotent retries cannot overwrite later changes to address
                // or identity even when region IDs still match this repair.
                foreach ($plan['changes'] as $i => $change) foreach ($change['before'] as $field => $value) {
                    if (isset($change['after'][$field])) continue;
                    self::require((string)$stores[$i][$field] === (string)$value, 'Store changed after prior repair');
                }
                $pdo->rollBack();return ['result' => 'ALREADY_APPLIED', 'stores' => 0, 'dictionaryWrites' => 0];
            }
            foreach ($plan['changes'] as $i => $change) foreach ($change['before'] as $field => $value) self::require((string)$stores[$i][$field] === (string)$value, 'Store baseline changed: ' . $change['before']['id']);
            $backup(['dictionaryBefore' => [], 'storesBefore' => $stores, 'storesAfter' => $expected, 'seedDigest' => self::digest($seed['rows'])]);
            $columns = ['id', 'path', 'parent_id', 'type', 'name', 'level', 'code', 'snum', 'create_time'];
            foreach (array_chunk($seed['rows'], 300) as $chunk) {
                $sql = 'INSERT INTO eb_city_area (`' . implode('`,`', $columns) . '`) VALUES ' . implode(',', array_fill(0, count($chunk), '(' . implode(',', array_fill(0, count($columns), '?')) . ')'));
                $values = [];
                foreach ($chunk as $row) foreach ($columns as $column) $values[] = $row[$column];
                $pdo->prepare($sql)->execute($values);
            }
            $update = $pdo->prepare('UPDATE eb_system_store SET province=?,city=?,area=? WHERE id=? AND province=0 AND city=0 AND area=0');
            foreach ($plan['changes'] as $change) {
                $update->execute([$change['after']['province'], $change['after']['city'], $change['after']['area'], $change['before']['id']]);
                self::require($update->rowCount() === 1, 'Concurrent store change');
            }
            $actual = $pdo->query('SELECT * FROM eb_system_store WHERE id IN (127,129,130) ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            self::require(self::digest($actual) === self::digest($expected), 'Out-of-scope store field changed');
            $actualDictionary = $pdo->query('SELECT * FROM eb_city_area ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            self::require(self::digest($actualDictionary) === self::digest($seed['rows']), 'Inserted dictionary differs');
            $result = ['result' => $dryRun ? 'DRY_RUN_PASS' : 'APPLIED', 'stores' => 3, 'dictionaryWrites' => count($seed['rows']), 'afterDigest' => self::digest($actual)];
            if ($dryRun) $pdo->rollBack();else $pdo->commit();
            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Recovery refuses later store edits. Standard dictionary rows are retained:
     * another address may already reference them after the committed repair.
     * Never delete a now-shared dictionary to roll back three store codes. */
    public static function rollback(PDO $pdo, array $snapshot): void
    {
        $pdo->beginTransaction();
        try {
            $dictionary = $pdo->query('SELECT * FROM eb_city_area ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            $stores = $pdo->query('SELECT * FROM eb_system_store WHERE id IN (127,129,130) ORDER BY id FOR UPDATE')->fetchAll(PDO::FETCH_ASSOC);
            self::require(self::digest($dictionary) === $snapshot['seedDigest'] && self::digest($stores) === self::digest($snapshot['storesAfter']), 'Later edits block rollback');
            $update = $pdo->prepare('UPDATE eb_system_store SET province=?,city=?,area=? WHERE id=?');
            foreach ($snapshot['storesBefore'] as $row) $update->execute([$row['province'], $row['city'], $row['area'], $row['id']]);
            $pdo->commit();
        } catch (Throwable $e) {if ($pdo->inTransaction()) $pdo->rollBack();throw $e;}
    }
}
