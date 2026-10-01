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
            Db::name('ai_member_rights_amount_state')->replace()->insert([
                'tenant_id'=>$tenant,'ready'=>0,'backfilled_at'=>0]);
            $last=0;$count=0;
            do {
                $ids=array_map('intval',Db::name('user_card_holder')->where('uid','>',$last)
                    ->group('uid')->order('uid','asc')->limit(100)->column('uid'));
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
        Db::name('ai_member_rights_amount_state')->replace()->insert([
            'tenant_id'=>$tenant,'ready'=>1,'backfilled_at'=>time()]);
        $output->writeln('AI_RIGHTS_READY '.$snapshots);
        return 0;
    }
}
