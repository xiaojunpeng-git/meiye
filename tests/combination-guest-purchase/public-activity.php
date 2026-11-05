<?php
declare(strict_types=1);
// Execute the real service/controller. The DAO doubles reject every operation
// except the two primary-key reads; guest landing must never settle a group.
namespace think\exception { class ValidateException extends \RuntimeException {} }
namespace app { class Request {} }
namespace app\services {
    class BaseServices {
        protected $dao;
        public function __call($name, $args) { return $this->dao->$name(...$args); }
    }
}
namespace app\dao\activity\combination {
    class StoreCombinationDao {
        public $row = ['id' => 11];
        public $reads = [];
        public function getOne($where, $fields) {
            $this->reads[] = [$where, $fields];
            return $this->row;
        }
    }
}
namespace app\services\activity\combination {
    class StorePinkServices {
        public $row = ['id' => 51, 'cid' => 11, 'uid' => 999, 'order_id_key' => 88, 'phone' => 'PRIVATE'];
        public $reads = [];
        public function getOne($where, $fields) {
            $this->reads[] = [$where, $fields];
            return $this->row;
        }
        public function __call($name, $args) { throw new \RuntimeException('Guest cannot invoke '.$name); }
    }
}
namespace {
    $backend = getenv('MEMBER_GUEST_BACKEND_ROOT') ?: dirname(__DIR__, 2).'/后端代码';
    require $backend.'/app/services/activity/combination/StoreCombinationServices.php';
    require $backend.'/app/controller/api/v1/activity/StoreCombination.php';
    $pink = new \app\services\activity\combination\StorePinkServices;
    $dao = new \app\dao\activity\combination\StoreCombinationDao;
    $service = new \app\services\activity\combination\StoreCombinationServices($dao);
    function app($name = null) {
        if ($name === 'json') return new class { public function successful($data) { return $data; } };
        return new class {
            public function make($class) {
                if ($class === \app\services\activity\combination\StorePinkServices::class) return $GLOBALS['pink'];
                throw new \RuntimeException('Unexpected guest dependency '.$class);
            }
        };
    }
    $checks = 0;
    function check($ok, $label) {
        if (!$ok) throw new \RuntimeException($label);
        $GLOBALS['checks']++;
        echo 'PASS '.$label.PHP_EOL;
    }
    $controller = new \app\controller\api\v1\activity\StoreCombination($service);
    $result = $controller->publicActivity(new \app\Request, 51);
    check($result === ['pink_id' => 51, 'combination_id' => 11], 'anonymous controller returns only public activity/group identity');
    check($pink->reads === [[['id' => 51], 'id,cid']], 'group lookup reads only required fields by primary key');
    check($dao->reads === [[['id' => 11, 'is_del' => 0, 'is_show' => 1], 'id']], 'deleted or hidden activities cannot be resolved');
    foreach ([0, -1] as $id) {
        try { $service->getPublicPinkActivity($id); throw new \RuntimeException('Expected invalid group rejection'); }
        catch (\think\exception\ValidateException $e) { check(true, 'invalid group rejected: '.$id); }
    }
    $pink->row = null;
    try { $service->getPublicPinkActivity(51); throw new \RuntimeException('Expected missing group rejection'); }
    catch (\think\exception\ValidateException $e) { check(true, 'missing group rejected'); }
    $pink->row = ['id' => 51, 'cid' => 11]; $dao->row = null;
    try { $service->getPublicPinkActivity(51); throw new \RuntimeException('Expected unavailable activity rejection'); }
    catch (\think\exception\ValidateException $e) { check(true, 'unavailable activity rejected'); }
    echo json_encode(['result' => 'PASS', 'checks' => $checks, 'writes' => 0]).PHP_EOL;
}
