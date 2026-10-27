<?php
declare(strict_types=1);

if (!class_exists('R51StaffIdentityPlanner', false)) require_once __DIR__.'/r51-010-staff-identity-plan.php';

/** Explicitly invoked PDO-only identity repair. No connection creation, credentials,
 * framework boot, account provisioning, jobs, permissions or historical writes.
 * The caller supplies the COMPLETE already-UID-mapped source inventory privately.
 */
final class R51StaffIdentityApplier
{
    /** Own the whole transaction; never commit/roll back someone else's transaction.
     * Allowlist must contain exactly the selected DB. Only the fixed 010 endpoint,
     * or explicitly guarded local Docker fixture databases, can pass this gate.
     * Return only PII-free planner reports/counts, never private insert payloads.
     */
    public static function apply(PDO $db, array $sourceStaff, string $expectedInputHash, array $databaseAllowlist): array
    {
        if ($db->inTransaction()) throw new RuntimeException('R51_IDENTITY_OUTER_TRANSACTION_FORBIDDEN');
        if (!preg_match('/^[a-f0-9]{64}$/D', $expectedInputHash)) throw new RuntimeException('R51_IDENTITY_INVALID_INPUT_HASH');
        self::databaseGuard($db, $databaseAllowlist);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // Preserve the caller's fetch/preparation settings: switching them can
        // change ZEROFILL serialization and invalidate a full-input snapshot.
        // Full primary-key locking reads cover rows and insertion gaps. SERIALIZABLE
        // also protects the read-only jobs inventory from concurrent edits/phantoms.
        $db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
        $db->beginTransaction();
        try {
            $staff=self::rows($db, 'eb_system_store_staff', true);
            $employees=self::rows($db, 'eb_employee', true);
            $jobs=self::rows($db, 'eb_staff_job_position', false);
            // Recheck after table reads hold transaction-lifetime metadata locks,
            // closing the preflight-to-transaction engine-change window.
            self::databaseGuard($db, $databaseAllowlist);
            $plan=R51StaffIdentityPlanner::plan($sourceStaff, $staff, $employees, $jobs);
            if (!hash_equals($expectedInputHash, $plan['report']['input_sha256']))
                throw new RuntimeException('R51_IDENTITY_INPUT_CHANGED');
            self::assertUsable($plan);
            $create=$plan['private']['employee_inserts']; $links=$plan['private']['staff_links'];
            $insert=$db->prepare('INSERT INTO eb_employee
                (id,name,phone,avatar,avatar_type,uid,status,is_del,add_time,employment_type_code)
                VALUES (?,?,?,?,?,NULL,?,?,?,\'internal\')');
            foreach ($create as $row) {
                if ($row['uid']!==null) throw new RuntimeException('R51_IDENTITY_NEW_UID_FORBIDDEN');
                // Profile/status are the fresh planner's source-derived values. Schema
                // defaults own all version fields; existing employees are never edited.
                $insert->execute([$row['id'],$row['name'],$row['phone'],$row['avatar'],$row['avatar_type'],
                    $row['status'],$row['is_del'],$row['add_time']]);
                if ($insert->rowCount()!==1) throw new RuntimeException('R51_IDENTITY_INSERT_COUNT_MISMATCH');
            }
            $update=$db->prepare('UPDATE eb_system_store_staff SET employee_id=? WHERE id=? AND employee_id <=> ?
                AND (employee_id IS NULL OR employee_id=0)');
            foreach ($links as $row) {
                $old=$row['expected_employee_id'];
                if ($old!==null && $old!==0 && $old!=='0') throw new RuntimeException('R51_IDENTITY_EXISTING_LINK_FORBIDDEN');
                $update->execute([$row['employee_id'],$row['staff_id'],$old]);
                if ($update->rowCount()!==1) throw new RuntimeException('R51_IDENTITY_LINK_COUNT_MISMATCH');
            }
            $post=R51StaffIdentityPlanner::plan($sourceStaff,
                self::rows($db, 'eb_system_store_staff', true), self::rows($db, 'eb_employee', true),
                self::rows($db, 'eb_staff_job_position', false));
            self::assertUsable($post);
            if ($post['private']['employee_inserts'] || $post['private']['staff_links'])
                throw new RuntimeException('R51_IDENTITY_POST_PLAN_NOT_EMPTY');
            $db->commit();
            return ['result'=>'PASS','input_sha256'=>$expectedInputHash,
                'created_employees'=>count($create),'linked_staff'=>count($links),
                'post_input_sha256'=>$post['report']['input_sha256'],'post_report'=>$post['report']];
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            // PDO diagnostics can contain phones/profile values. Expose allowed codes
            // only; the controller must never serialize raw database exceptions.
            if ($error instanceof RuntimeException && !($error instanceof PDOException)
                && preg_match('/^R51_IDENTITY_[A-Z_]+$/D', $error->getMessage())) throw $error;
            throw new RuntimeException('R51_IDENTITY_WRITE_FAILED');
        }
    }

    /** An isolated group is not a partial success: refuse the entire input batch. */
    private static function assertUsable(array $plan): void
    {
        if ($plan['report']['blocked'] || $plan['report']['isolated'])
            throw new RuntimeException('R51_IDENTITY_PLAN_BLOCKED_OR_ISOLATED');
    }

    /** Fixed table names avoid interpolating caller-controlled identifiers. */
    private static function rows(PDO $db, string $table, bool $lock): array
    {
        return $db->query('SELECT * FROM `'.$table.'` FORCE INDEX (PRIMARY) ORDER BY id'.($lock?' FOR UPDATE':''))
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    /** The whitelist narrows the fixed deployment boundary, never expands it.
     * Local fixtures require Docker + explicit flag + known local TCP hostname.
     * Every table must be transactional before starting any mutation.
     */
    private static function databaseGuard(PDO $db, array $allowlist): void
    {
        if (PHP_SAPI!=='cli' || $db->getAttribute(PDO::ATTR_DRIVER_NAME)!=='mysql')
            throw new RuntimeException('R51_IDENTITY_MYSQL_CLI_REQUIRED');
        $identity=$db->query('SELECT DATABASE() db,@@port port')->fetch(PDO::FETCH_ASSOC);
        $database=$identity['db']; $host=(string)$db->getAttribute(PDO::ATTR_CONNECTION_STATUS);
        $local=is_file('/.dockerenv') && getenv('R51_IDENTITY_LOCAL_TEST')==='1'
            && preg_match('/^r51_identity_test_[a-f0-9]{12}$/D', (string)$database)
            && preg_match('/^(mohe-mysql|mysql) via TCP\/IP$/D', $host);
        $fixed=$database==='mohe_010_app' && (int)$identity['port']===3307
            && preg_match('/^(127\.0\.0\.1 via TCP\/IP|localhost via UNIX socket)$/D', $host);
        if ($allowlist!==[$database] || (!$local && !$fixed)) throw new RuntimeException('R51_IDENTITY_DATABASE_NOT_ALLOWED');
        $engines=$db->query("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME IN ('eb_system_store_staff','eb_employee','eb_staff_job_position')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (count($engines)!==3 || array_filter($engines, static function ($engine) { return strtoupper($engine)!=='INNODB'; }))
            throw new RuntimeException('R51_IDENTITY_INNODB_REQUIRED');
    }
}
