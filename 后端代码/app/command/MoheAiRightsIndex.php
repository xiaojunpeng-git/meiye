<?php
declare(strict_types=1);

namespace app\command;

use app\services\query\metric\AiMemberRightsAmountIndex;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\facade\Db;

/**
 * Per-instance derived-index bootstrap/refresh. Source facts remain authoritative;
 * the reader stays on its old path until bootstrap validates every holder ID.
 * A running refresh process only rebuilds IDs marked by atomic source triggers.
 */
final class MoheAiRightsIndex extends Command
{
    protected function configure()
    {
        $this->setName('mohe-ai:rights-index')
            ->addOption('tenant',null,Option::VALUE_REQUIRED,'Tenant ID for this independent instance')
            ->addOption('bootstrap',null,Option::VALUE_NONE,'Backfill all holder members and activate the index')
            ->addOption('activate',null,Option::VALUE_NONE,'Verify a completed backfill and activate it')
            ->addOption('once',null,Option::VALUE_NONE,'Refresh one pending batch and exit')
            ->setDescription('Maintain the derived current member-rights amount index');
    }

    protected function execute(Input $input,Output $output)
    {
        $tenant=trim((string)$input->getOption('tenant'));
        if ($tenant==='' || !preg_match('/^[A-Za-z0-9_-]{1,32}$/D',$tenant)) {
            $output->writeln('AI_RIGHTS_TENANT_REQUIRED');return 1;
        }
        $index=new AiMemberRightsAmountIndex();
        if (!$index->schemaInstalled()) {$output->writeln('AI_RIGHTS_MIGRATION_REQUIRED');return 1;}
        if ($input->getOption('bootstrap')) {
            // Freeze an epoch before the first refresh. Activation checks that
            // every retained snapshot was recalculated under the current
            // cashier projection; changing code cannot bless old amounts.
            Db::name('ai_member_rights_amount_state')->replace()->insert([
                'tenant_id'=>$tenant,'ready'=>0,'backfilled_at'=>time(),'projection_version'=>0]);
            $last=0;$count=0;
            do {
                // Include old snapshot owners without a current holder too:
                // otherwise a removed right could leave a stale positive
                // amount eligible in the indexed population.
                $prefix=(string)Db::connect()->getConfig('prefix');
                $ids=array_map('intval',array_column(Db::query("SELECT member_id FROM (
                    SELECT uid member_id FROM {$prefix}user_card_holder WHERE uid>?
                    UNION SELECT member_id FROM {$prefix}ai_member_rights_amount_snapshot
                      WHERE tenant_id=? AND member_id>?
                    ) members ORDER BY member_id LIMIT 100",[$last,$tenant,$last]),'member_id'));
                if (!$ids) break;
                $index->refresh($tenant,$ids);
                $count+=count($ids);$last=max($ids);
                if ($count%5000===0) $output->writeln('AI_RIGHTS_BACKFILL '.$count);
            } while (true);
            return $this->activate($tenant,$output);
        }
        if ($input->getOption('activate')) return $this->activate($tenant,$output);
        if (!$index->ready($tenant)) {$output->writeln('AI_RIGHTS_BACKFILL_REQUIRED');return 1;}
        do {
            $ids=$index->pendingIds($tenant,100);
            if ($ids) {
                $done=$index->refresh($tenant,$ids);
                $output->writeln('AI_RIGHTS_REFRESHED '.$done);
            }
            if ($input->getOption('once')) break;
            if (!$ids) sleep(5);
        } while (true);
        return 0;
    }

    private function activate(string $tenant,Output $output): int
    {
        $state=Db::name('ai_member_rights_amount_state')->where('tenant_id',$tenant)
            ->field('ready,backfilled_at,projection_version')->find();
        $started=is_array($state)?(int)$state['backfilled_at']:0;
        if (!is_array($state) || (int)$state['ready']!==0 || $started<=0) {
            $output->writeln('AI_RIGHTS_FULL_REBUILD_REQUIRED');return 1;
        }
        $prefix=(string)Db::connect()->getConfig('prefix');
        // Validate identity coverage, not only two equal row counts. Triggers
        // were installed before backfill, so later inserts have a dirty mark.
        $missing=Db::query("SELECT h.uid FROM {$prefix}user_card_holder h
            LEFT JOIN {$prefix}ai_member_rights_amount_snapshot s
              ON s.member_id=h.uid AND s.tenant_id=?
            WHERE h.uid>0 AND s.member_id IS NULL LIMIT 1",[$tenant]);
        if ($missing) {$output->writeln('AI_RIGHTS_BACKFILL_INCOMPLETE');return 1;}
        $snapshots=(int)Db::name('ai_member_rights_amount_snapshot')
            ->where('tenant_id',$tenant)->count('member_id');
        if ($snapshots===0) {$output->writeln('AI_RIGHTS_BACKFILL_EMPTY');return 1;}
        $old=Db::name('ai_member_rights_amount_snapshot')->where('tenant_id',$tenant)
            ->where('calculated_at','<',$started)->value('member_id');
        if ($old!==null) {$output->writeln('AI_RIGHTS_FULL_REBUILD_INCOMPLETE');return 1;}
        Db::name('ai_member_rights_amount_state')->replace()->insert([
            'tenant_id'=>$tenant,'ready'=>1,'backfilled_at'=>time(),
            'projection_version'=>AiMemberRightsAmountIndex::PROJECTION_VERSION]);
        $output->writeln('AI_RIGHTS_READY '.$snapshots);
        return 0;
    }
}
