<?php
declare(strict_types=1);
namespace app\services\store\roster;
use think\exception\ValidateException;

/** 双入口薄适配：只解析认证上下文，共用查询、写命令、错误和导出契约。 */
trait StaffRosterControllerActions
{
    private function rosterResult(callable $read)
    {
        try { return $this->success($read()); }
        catch (StaffRosterConflict $e) { return $this->fail($e->getMessage(), ['conflicts' => $e->conflicts]); }
        catch (ValidateException | \InvalidArgumentException $e) { return $this->fail($e->getMessage()); }
    }
    public function index() { return $this->rosterResult(fn() => (new StaffRosterServices())->query($this->rosterStoreId(), (array)$this->request->get())); }
    public function exportList() { return $this->rosterResult(fn() => (new StaffRosterServices())->exportRows($this->rosterStoreId(), (array)$this->request->get())); }
    public function save() { return $this->mutate('schedule'); }
    public function shift() { return $this->mutate('shift'); }
    public function deleteShift() { return $this->mutate('delete-shift'); }
    private function mutate(string $action) { return $this->rosterResult(fn() => (new StaffRosterServices())->command($this->rosterStoreId(), $this->rosterActor(), $action, (array)$this->request->post())); }
}
