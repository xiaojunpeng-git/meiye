<?php
declare(strict_types=1);

namespace app\services\query\metric;

use app\services\cashier\v3\member\CashierV3MemberDetailQueryServices;
use think\facade\Db;

/**
 * AI-only derived lookup for the cashier's current remaining-project amount.
 * Source-table triggers mark member IDs in the same write transaction. Query
 * overlays every unprocessed ID and time transition with the shared authority,
 * so a delayed refresh cannot silently exclude a qualifying member.
 */
final class AiMemberRightsAmountIndex
{
    // Changes to the cashier card summary projection require a full rebuild;
    // source-row dirty marks cannot invalidate a value when the formula itself
    // changes. Old snapshots are never eligible for member selection.
    public const PROJECTION_VERSION=2;
    private const STALE_LIMIT=1000;
    private const NEVER_TRANSITIONS=9223372036854775807;

    /** A missing or not-yet-backfilled index leaves the legacy reader active. */
    public function ready(string $tenantId): bool
    {
        if ($tenantId==='') return false;
        if (!$this->schemaInstalled()) return false;
        $state=Db::name('ai_member_rights_amount_state')->where('tenant_id',$tenantId)
            ->field('ready,projection_version')->find();
        return is_array($state) && (int)$state['ready']===1
            && (int)$state['projection_version']===self::PROJECTION_VERSION;
    }

    /** Every read source must preserve the same transaction snapshot and mark contract. */
    public function schemaInstalled(): bool
    {
        $prefix=(string)Db::connect()->getConfig('prefix');
        $tables=['ai_member_rights_amount_dirty','ai_member_rights_amount_snapshot','ai_member_rights_amount_state',
            'user','store_user','user_card_holder','store_order','store_order_cart_info',
            'cashier_v3_card_state','cashier_v3_card_rule_state','cashier_v3_card_rule_component',
            'cashier_v3_card_purchase_receipt','cashier_v3_card_sale_item_allocation_fact'];
        $names=array_map(static fn(string $table):string=>$prefix.$table,$tables);
        $rows=Db::query('SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('
            .implode(',',array_fill(0,count($names),'?')).')',$names);
        if (count($rows)!==count($tables)) return false;
        foreach ($rows as $row) if (strtoupper((string)$row['ENGINE'])!=='INNODB') return false;
        $versionColumn=Db::query('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
            AND TABLE_NAME=? AND COLUMN_NAME=? LIMIT 1',[$prefix.'ai_member_rights_amount_state','projection_version']);
        if ($versionColumn===[]) return false;
        // A ready snapshot is safe only while every source write still marks
        // the same transaction. Count alone could hide one missing trigger.
        $expected=[];
        foreach (['holder','cart','order','card_state','rule_state','rule_component','receipt','allocation'] as $source)
            foreach (['ai','au','ad'] as $event) $expected[]=$prefix.'ai_rights_'.$source.'_'.$event;
        $triggers=Db::query('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS
            WHERE TRIGGER_SCHEMA=DATABASE() AND TRIGGER_NAME LIKE ?',[$prefix.'ai_rights_%']);
        $actual=array_column($triggers,'TRIGGER_NAME');sort($actual);sort($expected);
        if ($actual!==$expected) throw new MetricQueryContractException(
            'AI_RIGHTS_INDEX_INVALID','当前权益数据索引不可用。');
        return true;
    }

    /**
     * Return null only before cutover. Once ready, every scoped stale identity
     * must be reconciled in the same read snapshot; a backlog fails closed.
     */
    public function query(string $tenantId,array $stores,string $operator,int $threshold,int $limit): ?array
    {
        if (!$this->ready($tenantId)) return null;
        if (!$stores || !in_array($operator,['gt','gte'],true) || $threshold<0 || $limit<0
            || $limit>RegisteredMetricReadServices::rightsDetailLimit()) throw new MetricQueryContractException('METRIC_QUERY_SHAPE_UNAVAILABLE','当前查询条件不可用。');
        $now=time();
        $stale=$this->staleIds($tenantId,$stores,$now);
        if (count($stale)>self::STALE_LIMIT) throw new MetricQueryContractException(
            'AI_RIGHTS_INDEX_CATCHUP_REQUIRED','当前权益数据正在同步，请稍后重试。');
        $staleAmounts=[];$detail=new CashierV3MemberDetailQueryServices();
        foreach (array_chunk($stale,100) as $batch) {
            foreach ($detail->readAiRemainingProjectAmounts($batch,$tenantId,$stores) as $id=>$amount) {
                if ($this->matches($amount,$operator,$threshold)) $staleAmounts[$id]=$amount;
            }
        }
        $base=Db::name('ai_member_rights_amount_snapshot')->alias('s')
            ->join('user u','u.uid=s.member_id')
            ->where('s.tenant_id',$tenantId)->where('u.status',1)->where('u.is_del',0)
            ->whereIn('s.member_id',static function($query)use($stores):void {
                $query->name('store_user')->where('status',1)->whereIn('store_id',$stores)->field('uid');
            })
            ->where('s.amount_cents',$operator==='gt'?'>':'>=',$threshold);
        if ($stale) $base->whereNotIn('s.member_id',$stale);
        $cleanCount=(int)(clone $base)->count('s.member_id');
        $count=$cleanCount+count($staleAmounts);
        if ($limit===0 || $count===0) return ['count'=>$count,'rows'=>[],'limit'=>$limit,'has_more'=>$count>0];
        $rows=[];
        if ($staleAmounts) {
            // ThinkORM column() cannot address a computed field alias. Keep
            // the stale overlay's identity projection on the same live user
            // rows and use the same fallback order as the clean SQL branch.
            $nameRows=Db::name('user')->whereIn('uid',array_keys($staleAmounts))
                ->field('uid,real_name,nickname,phone')->select()->toArray();
            $names=[];
            foreach ($nameRows as $member) {
                $id=(int)$member['uid'];
                $names[$id]=(string)($member['real_name']?:($member['nickname']?:($member['phone']?:'会员#'.$id)));
            }
            foreach ($staleAmounts as $id=>$amount) {
                if (!isset($names[$id])) throw new MetricQueryContractException('METRIC_SOURCE_RESULT_INVALID','会员身份已发生变化。');
                $rows[$id]=['member_id'=>$id,'member_name'=>(string)$names[$id],
                    'amount_cents'=>$amount];
            }
        }
        ksort($rows,SORT_NUMERIC);
        // The complete source is spooled from the same repeatable-read scope;
        // a screen page is never mistaken for the file's business population.
        // Keeping only 500 clean rows per SQL page avoids the old 1428-member
        // worksheet limit in this Reader without materializing all identities.
        $ordered=(static function()use($base,$rows):\Generator {
            $stale=array_values($rows);$stalePosition=0;$cursor=0;
            do {
                $clean=(clone $base)->where('s.member_id','>',$cursor)
                    ->fieldRaw('s.member_id,s.amount_cents,'
                        ."COALESCE(NULLIF(u.real_name,''),NULLIF(u.nickname,''),NULLIF(u.phone,''),CONCAT('会员#',u.uid)) member_name")
                    ->order('s.member_id','asc')->limit(500)->select()->toArray();
                foreach ($clean as $item) {
                    $id=(int)$item['member_id'];
                    while (isset($stale[$stalePosition]) && $stale[$stalePosition]['member_id']<$id) {
                        yield $stale[$stalePosition++];
                    }
                    yield ['member_id'=>$id,'member_name'=>(string)$item['member_name'],
                        'amount_cents'=>(int)$item['amount_cents']];
                    $cursor=$id;
                }
            } while (count($clean)===500);
            while (isset($stale[$stalePosition])) yield $stale[$stalePosition++];
        })();
        $snapshot=(new AiMemberRightsPopulationStore())->put($ordered,$count);
        $page=[];
        foreach ((new AiMemberRightsPopulationStore())->rows($snapshot) as $item) {
            $page[]=['member_id'=>$item['member_id'],'member_name'=>$item['member_name'],
                'metrics'=>['member_remaining_project_amount'=>$item['amount_cents']]];
            if (count($page)===$limit) break;
        }
        return ['count'=>$count,'rows'=>$page,'limit'=>$limit,'has_more'=>$count>count($page),
            'population_snapshot'=>$snapshot];
    }

    /**
     * Summary-only detail for an already signed member set. Check visibility
     * again, then overlay every changed or time-transitioned source before
     * returning the same three card-derived values as cashier member detail.
     */
    public function summaryForMembers(string $tenantId,array $stores,array $memberIds): ?array
    {
        if (!$this->ready($tenantId)) return null;
        $memberIds=array_values(array_unique($memberIds));
        if (!$memberIds || count($memberIds)>RegisteredMetricReadServices::rightsDetailLimit()
            || !$stores) throw new MetricQueryContractException('METRIC_QUERY_SHAPE_UNAVAILABLE','当前会员范围不可用。');
        foreach ($memberIds as $id) if (!is_int($id)||$id<1) throw new MetricQueryContractException(
            'METRIC_QUERY_SHAPE_UNAVAILABLE','当前会员范围不可用。');
        $visible=Db::name('user')->alias('u')->whereIn('u.uid',$memberIds)
            ->where('u.status',1)->where('u.is_del',0)
            ->whereIn('u.uid',static function($query)use($stores):void {
                $query->name('store_user')->where('status',1)->whereIn('store_id',$stores)->field('uid');
            })->column('u.uid');
        if (count($visible)!==count($memberIds)) throw new MetricQueryContractException(
            'METRIC_PERMISSION_DENIED','当前会员范围已发生变化。');
        $snapshots=Db::name('ai_member_rights_amount_snapshot')
            ->where('tenant_id',$tenantId)->whereIn('member_id',$memberIds)
            ->field('member_id,amount_cents,active_card_count,remaining_project_times,processed_version,next_transition_at')
            ->select()->toArray();
        $byId=[];foreach ($snapshots as $row) $byId[(int)$row['member_id']]=$row;
        $versions=Db::name('ai_member_rights_amount_dirty')->whereIn('member_id',$memberIds)
            ->column('version','member_id');
        $now=time();$stale=[];$result=[];
        foreach ($memberIds as $id) {
            $row=$byId[$id]??null;
            if (!$row || (int)$row['processed_version']!==(int)($versions[$id]??0)
                || (int)$row['next_transition_at']<=$now) {
                $stale[]=$id;continue;
            }
            $result[$id]=['amount_cents'=>(int)$row['amount_cents'],
                'active_card_count'=>(int)$row['active_card_count'],
                'remaining_project_times'=>(int)$row['remaining_project_times']];
        }
        $reader=new CashierV3MemberDetailQueryServices();
        foreach (array_chunk($stale,100) as $batch) {
            foreach ($reader->readAiRightsSummariesForIndex($batch,$tenantId) as $id=>$row) $result[$id]=$row;
        }
        if (count($result)!==count($memberIds)) throw new MetricQueryContractException(
            'METRIC_SOURCE_RESULT_INVALID','当前会员权益读取不完整。');
        return $result;
    }

    /**
     * Refresh a bounded batch from the same cashier projection used by detail.
     * The optimistic dirty version prevents an intervening checkout from being
     * marked processed; the next query will overlay its exact current value.
     */
    public function refresh(string $tenantId,array $memberIds): int
    {
        $memberIds=array_values(array_unique(array_map('intval',$memberIds)));
        if ($tenantId==='' || !$memberIds || count($memberIds)>100 || min($memberIds)<1) {
            throw new \InvalidArgumentException('AI_RIGHTS_REFRESH_SCOPE_INVALID');
        }
        $before=Db::name('ai_member_rights_amount_dirty')->whereIn('member_id',$memberIds)->column('version','member_id');
        $reader=new CashierV3MemberDetailQueryServices();
        $summaries=$reader->readAiRightsSummariesForIndex($memberIds,$tenantId);
        $now=time();
        $transitions=$this->nextTransitions($memberIds,$now);
        $after=Db::name('ai_member_rights_amount_dirty')->whereIn('member_id',$memberIds)->column('version','member_id');
        $done=0;
        foreach ($memberIds as $id) {
            if ((int)($before[$id]??0)!==(int)($after[$id]??0)) continue;
            $version=(int)($after[$id]??0);
            Db::name('ai_member_rights_amount_snapshot')->replace()->insert([
                'tenant_id'=>$tenantId,'member_id'=>$id,
                'amount_cents'=>$summaries[$id]['amount_cents'],
                'active_card_count'=>$summaries[$id]['active_card_count'],
                'remaining_project_times'=>$summaries[$id]['remaining_project_times'],
                'processed_version'=>$version,'next_transition_at'=>$transitions[$id],
                'calculated_at'=>$now]);
            ++$done;
        }
        return $done;
    }

    /** Pending IDs include source writes and cards becoming valid/expired. */
    public function pendingIds(string $tenantId,int $limit=100): array
    {
        if ($limit<1 || $limit>self::STALE_LIMIT) throw new \InvalidArgumentException('AI_RIGHTS_REFRESH_LIMIT_INVALID');
        $prefix=(string)Db::connect()->getConfig('prefix');
        $sql="SELECT member_id FROM (
            SELECT d.member_id FROM {$prefix}ai_member_rights_amount_dirty d
            LEFT JOIN {$prefix}ai_member_rights_amount_snapshot s
              ON s.member_id=d.member_id AND s.tenant_id=?
            WHERE s.member_id IS NULL OR s.processed_version<>d.version
            UNION
            SELECT member_id FROM {$prefix}ai_member_rights_amount_snapshot
            WHERE tenant_id=? AND next_transition_at<=?
        ) pending ORDER BY member_id LIMIT ".(int)$limit;
        return array_map('intval',array_column(Db::query($sql,[$tenantId,$tenantId,time()]),'member_id'));
    }

    private function staleIds(string $tenantId,array $stores,int $now): array
    {
        $prefix=(string)Db::connect()->getConfig('prefix');
        $marks=implode(',',array_fill(0,count($stores),'?'));
        $scope="EXISTS (SELECT 1 FROM {$prefix}store_user su WHERE su.uid=u.uid
          AND su.status=1 AND su.store_id IN ($marks))";
        $sql="SELECT member_id FROM (
          SELECT d.member_id FROM {$prefix}ai_member_rights_amount_dirty d
          LEFT JOIN {$prefix}ai_member_rights_amount_snapshot s
            ON s.member_id=d.member_id AND s.tenant_id=?
          JOIN {$prefix}user u ON u.uid=d.member_id AND u.status=1 AND u.is_del=0
          WHERE (s.member_id IS NULL OR s.processed_version<>d.version) AND $scope
          UNION
          SELECT s.member_id FROM {$prefix}ai_member_rights_amount_snapshot s
          JOIN {$prefix}user u ON u.uid=s.member_id AND u.status=1 AND u.is_del=0
          WHERE s.tenant_id=? AND s.next_transition_at<=? AND $scope
        ) stale ORDER BY member_id LIMIT ".(self::STALE_LIMIT+1);
        $params=array_merge([$tenantId],$stores,[$tenantId,$now],$stores);
        return array_map('intval',array_column(Db::query($sql,$params),'member_id'));
    }

    private function nextTransitions(array $ids,int $now): array
    {
        $next=array_fill_keys($ids,self::NEVER_TRANSITIONS);
        $holders=Db::name('user_card_holder')->whereIn('uid',$ids)
            ->field('uid,oid,write_start,write_end')->select()->toArray();
        $owners=[];
        foreach ($holders as $holder) {
            $uid=(int)$holder['uid'];$owners[(int)$holder['oid']]=$uid;
            $this->considerTime($next[$uid],(int)$holder['write_start'],false,$now);
            $this->considerTime($next[$uid],(int)$holder['write_end'],true,$now);
        }
        foreach (array_chunk(array_keys($owners),1000) as $orderIds) {
            if (!$orderIds) continue;
            $carts=Db::name('store_order_cart_info')->whereIn('oid',$orderIds)
                ->where('cart_type',2)->where('product_type',6)->where('is_writeoff',0)
                ->field('oid,write_start,write_end')->select()->toArray();
            foreach ($carts as $cart) {
                $uid=$owners[(int)$cart['oid']];
                $this->considerTime($next[$uid],(int)$cart['write_start'],false,$now);
                $this->considerTime($next[$uid],(int)$cart['write_end'],true,$now);
            }
        }
        return $next;
    }

    private function considerTime(int &$next,int $time,bool $inclusiveEnd,int $now): void
    {
        if ($time<=0 || $time>=self::NEVER_TRANSITIONS-1) return;
        $change=$inclusiveEnd?$time+1:$time;
        if ($change>$now && $change<$next) $next=$change;
    }

    private function matches(int $amount,string $operator,int $threshold): bool
    {
        return $operator==='gt'?$amount>$threshold:$amount>=$threshold;
    }
}
