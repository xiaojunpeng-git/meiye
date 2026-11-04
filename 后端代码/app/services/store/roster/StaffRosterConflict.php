<?php
declare(strict_types=1);
namespace app\services\store\roster;
/** 事务拒绝时返回可处理的预约冲突，调用方不得取消预约来强行保存。 */
final class StaffRosterConflict extends \RuntimeException
{
    public array $conflicts;
    public function __construct(array $conflicts) { parent::__construct('排班与现有预约冲突，请先处理以下预约。'); $this->conflicts = $conflicts; }
}
