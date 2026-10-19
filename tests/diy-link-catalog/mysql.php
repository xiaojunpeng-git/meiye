<?php
/** Isolated MySQL 5.7 rehearsal; temporary database is always dropped.
 * Stub only the framework shell, execute the actual category tree service.
 */
declare(strict_types=1);
namespace app\services { class BaseServices { protected $dao; } }
namespace app\dao\diy {
    class PageCategoryDao {
        private $p;
        public function __construct(\PDO $p) { $this->p = $p; }
        public function getList(array $where, string $fields): array {
            $q = $this->p->prepare('SELECT '.$fields.' FROM eb_page_category WHERE pid=? ORDER BY sort DESC,id');
            $q->execute([$where['pid']]); return $q->fetchAll();
        }
    }
}
namespace mohe\services {
    class CacheService {
        public static $values = [];
        public static function get($key, $callback, $ttl) {
            if (!array_key_exists($key, self::$values)) self::$values[$key] = $callback();
            return self::$values[$key];
        }
    }
}
namespace {
    $root = dirname(__DIR__, 2);
    require $root.'/scripts/import/customer_008_link_catalog.php';
    require $root.'/后端代码/app/services/diy/PageCategoryServices.php';
    function demand($ok, $message): void { if (!$ok) throw new \RuntimeException($message); }
    function refuses(callable $fn, string $message): void {
        try { $fn(); } catch (\Throwable $e) { return; }
        throw new \RuntimeException($message);
    }
    $options = [\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION, \PDO::ATTR_DEFAULT_FETCH_MODE=>\PDO::FETCH_ASSOC, \PDO::ATTR_STRINGIFY_FETCHES=>true, \PDO::ATTR_EMULATE_PREPARES=>false];
    $p = new \PDO('mysql:host=127.0.0.1;port=13318;charset=utf8mb4', 'root', 'rights-test-local', $options);
    demand(strpos($p->getAttribute(\PDO::ATTR_SERVER_VERSION), '5.7.') === 0, 'MySQL 5.7 required');
    $db = 'test_lc008_'.bin2hex(random_bytes(5)); $dir = sys_get_temp_dir().'/'.$db;
    mkdir($dir, 0700);
    $p->exec('CREATE DATABASE `'.$db.'` CHARACTER SET utf8mb4');
    try {
        $p->exec('USE `'.$db.'`');
        $p->exec("SET SESSION sql_mode='STRICT_ALL_TABLES'");
        $p->exec("CREATE TABLE eb_page_category(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,pid INT NOT NULL DEFAULT 0,type VARCHAR(50) NOT NULL,name VARCHAR(50) NOT NULL,sort SMALLINT NOT NULL,status TINYINT NOT NULL,add_time INT NOT NULL) ENGINE=InnoDB CHARSET=utf8");
        $p->exec("CREATE TABLE eb_page_link(id INT NOT NULL AUTO_INCREMENT PRIMARY KEY,cate_id INT NOT NULL,type TINYINT NOT NULL,name VARCHAR(50) NOT NULL,url VARCHAR(255) NOT NULL,param VARCHAR(255) NOT NULL,example VARCHAR(255) NOT NULL,status TINYINT NOT NULL,sort SMALLINT NOT NULL,add_time INT NOT NULL) ENGINE=InnoDB CHARSET=utf8");
        $p->exec("CREATE TABLE eb_database_upgrade_log(id BIGINT AUTO_INCREMENT PRIMARY KEY,upgrade_key VARCHAR(100) NOT NULL UNIQUE,title VARCHAR(255) NOT NULL,task_file VARCHAR(500) NOT NULL,sql_checksum CHAR(64) NOT NULL,code_version VARCHAR(100) NOT NULL,executed_at DATETIME NOT NULL,executed_by VARCHAR(100) NOT NULL,result_note VARCHAR(500) NOT NULL) ENGINE=InnoDB");
        $p->exec("CREATE TABLE sentinel(id INT PRIMARY KEY,value VARCHAR(50)) ENGINE=InnoDB");
        $p->exec("INSERT INTO sentinel VALUES(1,'business-and-decoration-unchanged')");
        $catalog = lc008Catalog($root.'/后端代码/database/upgrades/2026-10-19-008链接目录补齐/ruihao-catalog.json');
        $service = new \app\services\diy\PageCategoryServices(new \app\dao\diy\PageCategoryDao($p));
        demand($service->getCategroyList() === [], 'Original blank catalog not reproduced');
        // A missing private snapshot blocks all writes before the first insert.
        refuses(function () use ($p,$catalog,$dir) { lc008Apply($p,$catalog,$dir.'/missing',str_repeat('a',40)); }, 'Missing backup accepted');
        demand(lc008Rows($p) === ['eb_page_category'=>[],'eb_page_link'=>[]], 'Backup failure mutated target');
        // Failure at the final audit statement must undo all 77 inserted rows.
        $p->exec("CREATE TRIGGER reject_audit BEFORE INSERT ON eb_database_upgrade_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='injected audit failure'");
        refuses(function () use ($p,$catalog,$dir) { lc008Apply($p,$catalog,$dir,str_repeat('a',40)); }, 'Audit failure not raised');
        demand(lc008Rows($p) === ['eb_page_category'=>[],'eb_page_link'=>[]], 'Atomic rollback failed');
        $p->exec('DROP TRIGGER reject_audit'); unlink($dir.'/before.json');
        // Empty range locks must prevent another session inserting midway.
        $other = new \PDO('mysql:host=127.0.0.1;port=13318;dbname='.$db.';charset=utf8mb4', 'root', 'rights-test-local', $options);
        $other->exec('SET SESSION innodb_lock_wait_timeout=1');
        lc008Begin($p); lc008Rows($p,true);
        refuses(function () use ($other) { $other->exec("INSERT INTO eb_page_category VALUES(99,0,'link','concurrent',1,1,1)"); }, 'Empty insertion gap was not locked');
        $p->rollBack();
        $receipt = lc008Apply($p,$catalog,$dir,str_repeat('a',40));
        demand(lc008Rows($p) === $catalog, 'Source byte parity failed');
        demand(strlen(lc008Json($receipt)) <= 500, 'Audit varchar(500) overflow');
        demand((fileperms($dir.'/before.json') & 0077) === 0, 'Snapshot is not private');
        refuses(function () use ($p,$catalog,$dir) { lc008Apply($p,$catalog,$dir,str_repeat('a',40)); }, 'Repeat import accepted');
        // The targeted invalidation is necessary: the actual service caches [].
        demand($service->getCategroyList() === [], 'Stale empty cache not reproduced');
        unset(\mohe\services\CacheService::$values['tree_page_categroy']);
        $tree = $service->getCategroyList();
        demand(count($tree) === 4 && $tree[0]['children'][0]['id'] === '5', 'Actual initial category selection failed');
        $snapshot = json_decode(file_get_contents($dir.'/before.json'), true, 512, JSON_THROW_ON_ERROR);
        $p->exec("UPDATE eb_page_link SET name='later-edit' WHERE id=47");
        refuses(function () use ($p,$snapshot,$catalog) { lc008Rollback($p,$snapshot,$catalog); }, 'Rollback overwrote later edit');
        demand($p->query('SELECT name FROM eb_page_link WHERE id=47')->fetchColumn() === 'later-edit', 'Later edit was lost');
        $q = $p->prepare('UPDATE eb_page_link SET name=? WHERE id=47'); $q->execute(['商城首页']);
        lc008Rollback($p,$snapshot,$catalog);
        demand(lc008Rows($p) === $snapshot['before'], 'Full rollback failed');
        demand(strpos($p->query('SELECT result_note FROM eb_database_upgrade_log')->fetchColumn(), 'ROLLED_BACK') !== false, 'Audit erased');
        refuses(function () use ($p,$catalog,$dir) { lc008Apply($p,$catalog,$dir,str_repeat('a',40)); }, 'Rolled-back receipt allowed unsafe replay');
        $p->exec("DELETE FROM eb_database_upgrade_log");
        $p->exec("INSERT INTO eb_page_category VALUES(99,0,'link','user-data',1,1,1)");
        refuses(function () use ($p,$catalog,$dir) { lc008Apply($p,$catalog,$dir,str_repeat('a',40)); }, 'Nonempty target overwritten');
        demand($p->query('SELECT value FROM sentinel')->fetchColumn() === 'business-and-decoration-unchanged', 'Unrelated data changed');
        echo lc008Json(['status'=>'PASS','cases'=>['original-empty','backup-required','atomic-audit-failure','gap-lock','77-row-byte-parity','bounded-audit','private-backup','repeat-refused','actual-tree-service','targeted-cache','later-edit-protection','full-rollback','audit-retained','nonempty-protection','unrelated-data-preserved'],'tree'=>$tree,'links'=>$catalog['eb_page_link']]).PHP_EOL;
    } finally {
        if ($p->inTransaction()) $p->rollBack();
        $p->exec('DROP DATABASE `'.$db.'`');
        if (is_file($dir.'/before.json')) unlink($dir.'/before.json');
        rmdir($dir);
    }
}
