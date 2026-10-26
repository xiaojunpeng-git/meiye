<?php
declare(strict_types=1);

namespace app\services\fund;

use app\services\other\export\ExportServices;
use think\facade\Db;

final class StoreFundServices
{
    // 收支单分页是固定业务契约，服务端不接受客户端缩小或放大页尺寸。
    private const DOCUMENT_PAGE_SIZE = 100;
    // 台账分页同样固定为 100 条，余额必须先连续计算再截取当前页。
    private const LEDGER_PAGE_SIZE = 100;

    public function subjects(bool $includeDisabled = false): array
    {
        $query = Db::name('store_fund_subject')->order('sort_order asc,id asc');
        if (!$includeDisabled) $query->where('is_enabled', 1);
        return $query->select()->toArray();
    }

    public function saveSubject(array $input): array
    {
        $id = (int)($input['id'] ?? 0); $name = trim((string)($input['subject_name'] ?? ''));
        $code = strtoupper(trim((string)($input['subject_code'] ?? '')));
        $type = strtoupper(trim((string)($input['subject_type'] ?? 'NORMAL')));
        if (!preg_match('/^[A-Z0-9_]{2,64}$/', $code) || $name === '' || !in_array($type, ['NORMAL', 'SPECIAL'], true)) throw new \InvalidArgumentException('fund_subject_input_invalid');
        $data = ['subject_code'=>$code,'subject_name'=>$name,'subject_type'=>$type,'sort_order'=>max(0,(int)($input['sort_order'] ?? 0)),'is_enabled'=>(int)!empty($input['is_enabled']),'updated_at'=>date('Y-m-d H:i:s')];
        if ($id) { Db::name('store_fund_subject')->where('id',$id)->update($data); }
        else { $data['created_at']=$data['updated_at']; $id=(int)Db::name('store_fund_subject')->insertGetId($data); }
        return (array)Db::name('store_fund_subject')->where('id',$id)->find();
    }

    /**
     * 单据是列表和分页的唯一粒度；当前页明细批量随单据返回，供前端分组展示。
     * 已逻辑删除的单据不进入状态筛选或分页总数，明细也不能脱离权限范围单独暴露。
     */
    public function listDocuments(array $scope, array $filters): array
    {
        $query = Db::name('store_fund_document')->alias('d')->leftJoin('system_store s','s.id=d.store_id')
            ->field('d.*,s.name as store_name')->where('d.document_status','<>','DELETED');
        $this->applyScope($query, $scope); $this->applyFilters($query, $filters, false);
        if (!empty($filters['subject_id'])) $query->whereExists(function ($sub) use ($filters) { $sub->name('store_fund_document_line')->whereRaw('document_id=d.id')->where('subject_id',(int)$filters['subject_id']); });
        $page=max(1,(int)($filters['page']??1)); $limit=self::DOCUMENT_PAGE_SIZE;
        $count=(clone $query)->count();
        $rows=$query->order('d.business_date desc,d.id desc')->page($page,$limit)->select()->toArray();
        $linesByDocument = [];
        if ($rows) {
            $documentIds = array_map('intval', array_column($rows, 'id'));
            $lines = Db::name('store_fund_document_line')
                ->whereIn('document_id', $documentIds)
                ->order('document_id asc,line_no asc')
                ->select()->toArray();
            foreach ($lines as $line) {
                $line['amount_cents'] = (int)$line['amount_cents'];
                $line['amount'] = $this->amount($line['amount_cents']);
                $linesByDocument[(int)$line['document_id']][] = $line;
            }
        }
        foreach ($rows as &$row) {
            $row['lines'] = $linesByDocument[(int)$row['id']] ?? [];
            $row['line_count'] = count($row['lines']);
            $row['amount_cents'] = array_sum(array_column($row['lines'], 'amount_cents'));
            $row['amount'] = $this->amount((int)$row['amount_cents']);
        }
        unset($row);
        return ['list'=>$rows,'count'=>$count,'page'=>$page,'limit'=>$limit];
    }

    /** 对外详情不重新开放已删除单据；审计仍可从保留的业务记录追溯。 */
    public function detail(array $scope, int $id): array
    {
        $query=Db::name('store_fund_document')->where('id',$id); $this->applyScope($query,$scope); $document=(array)$query->find();
        if (!$document || $document['document_status'] === 'DELETED') throw new \RuntimeException('fund_document_not_found');
        $document['lines']=Db::name('store_fund_document_line')->where('document_id',$id)->order('line_no')->select()->toArray();
        return $document;
    }

    /**
     * 保存仅修改未审核主单及明细，不产生台账事实、不改原录入人和单号。
     * 本店他人单据编辑须有服务端授权，并携带详情修订计数防止旧表单覆盖新内容。
     */
    public function save(array $scope, array $actor, array $input): array
    {
        $id=(int)($input['id']??0); $storeId=$this->scopeStoreId($scope,$input); $direction=strtoupper(trim((string)($input['direction']??'')));
        $date=trim((string)($input['business_date']??'')); $lines=$input['lines']??[];
        if (!in_array($direction,['INCOME','EXPENSE'],true) || !$this->validDate($date) || !is_array($lines) || !$lines) throw new \InvalidArgumentException('fund_document_input_invalid');
        return Db::transaction(function () use($id,$storeId,$direction,$date,$lines,$input,$actor,$scope) {
            $now=date('Y-m-d H:i:s');
            $revisionUpdate = [];
            if ($id) {
                $existingQuery=Db::name('store_fund_document')->where('id',$id)->lock(true); $this->applyScope($existingQuery,$scope); $existing=(array)$existingQuery->find();
                if (!$existing || $existing['document_status']!=='DRAFT') throw new \RuntimeException('fund_document_not_editable');
                $canEditStoreDocument = ($scope['mode'] ?? '') === 'store'
                    && (int)($scope['store_id'] ?? 0) > 0
                    && (int)$existing['store_id'] === (int)$scope['store_id']
                    && ($actor['type'] ?? '') === 'STORE_STAFF' && (int)($actor['id'] ?? 0) > 0
                    && ($actor['can_edit_store_documents'] ?? false) === true;
                if (!$canEditStoreDocument) $this->assertDocumentOperator($existing,$actor);
                if ($canEditStoreDocument) {
                    // audit_revision 是既有零基修订计数，必须明确携带；0 不表示跳过校验。
                    $expectedRevision = filter_var($input['audit_revision'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
                    if ($expectedRevision === false || $expectedRevision !== (int)$existing['audit_revision']) {
                        throw new \RuntimeException('fund_document_revision_conflict');
                    }
                    $revisionUpdate = ['audit_revision'=>(int)$existing['audit_revision']+1];
                }
            }
            else { $id=(int)Db::name('store_fund_document')->insertGetId(['document_no'=>$this->documentNo(),'store_id'=>$storeId,'business_date'=>$date,'direction'=>$direction,'document_status'=>'DRAFT','summary'=>'','remark'=>'','attachments_json'=>'[]','source_document_id'=>0,'created_by_type'=>$actor['type'],'created_by_id'=>$actor['id'],'created_by_name_snapshot'=>$actor['name'],'created_at'=>$now,'updated_at'=>$now]); }
            Db::name('store_fund_document')->where('id',$id)->update($revisionUpdate + ['store_id'=>$storeId,'business_date'=>$date,'direction'=>$direction,'summary'=>trim((string)($input['summary']??'')),'remark'=>trim((string)($input['remark']??'')),'attachments_json'=>json_encode(array_values((array)($input['attachments']??[])),JSON_UNESCAPED_UNICODE),'updated_at'=>$now]);
            // 未审核明细沿用原事务内替换流程；任一科目或金额无效会连同主单更新全部回滚。
            Db::name('store_fund_document_line')->where('document_id',$id)->delete();
            foreach ($lines as $index=>$line) $this->insertLine($id,$index+1,(array)$line,$now);
            return $this->detail(['mode'=>'platform','store_ids'=>[$storeId]],$id);
        });
    }

    /**
     * 单据状态决定既有台账生效范围；撤销不删明细、不改金额或科目快照。
     * 本店审核与撤销授权仅接受控制器判权后的 actor 标记；平台操作仍限录入人。
     */
    public function audit(array $scope, array $actor, int $id, bool $audited): array
    {
        return Db::transaction(function () use($scope,$actor,$id,$audited) {
            $query=Db::name('store_fund_document')->where('id',$id)->lock(true); $this->applyScope($query,$scope); $row=(array)$query->find();
            // 删除终态不能通过旧客户端的审核或撤销请求复活。
            if (!$row || $row['document_status'] === 'DELETED') throw new \RuntimeException('fund_document_not_found');
            $canAuditStoreDocument = ($scope['mode'] ?? '') === 'store'
                && (int)($scope['store_id'] ?? 0) > 0
                && (int)$row['store_id'] === (int)$scope['store_id']
                && ($actor['type'] ?? '') === 'STORE_STAFF' && (int)($actor['id'] ?? 0) > 0
                && ($actor['can_audit_store_documents'] ?? false) === true;
            if (!$canAuditStoreDocument) $this->assertDocumentOperator($row,$actor);
            $status=$audited?'APPROVED':'DRAFT';
            // 行锁串行化本店审核和撤销；同目标重试不增加修订号，平台原有行为保持不变。
            if ($canAuditStoreDocument && $row['document_status'] === $status) return $this->detail($scope,$id);
            Db::name('store_fund_document')->where('id',$id)->update(['document_status'=>$status,'audited_by_type'=>$audited?$actor['type']:'','audited_by_id'=>$audited?$actor['id']:0,'audited_at'=>$audited?date('Y-m-d H:i:s'):null,'audit_revision'=>(int)$row['audit_revision']+1,'updated_at'=>date('Y-m-d H:i:s')]);
            return $this->detail($scope,$id);
        });
    }

    /**
     * 未审核单据逻辑删除：与审核共用原单行锁，不删除历史金额、明细或关联。
     * 本店有费用权限人员可删除他人录入的未审核单；授权标记只接受控制器判权结果。
     */
    public function deleteDraft(array $scope, array $actor, int $id): array
    {
        // 当前仅门店端开放删除，不借此增加平台或无门店上下文的写入口。
        if (($scope['mode'] ?? '') !== 'store' || (int)($scope['store_id'] ?? 0) <= 0
            || ($actor['type'] ?? '') !== 'STORE_STAFF' || (int)($actor['id'] ?? 0) <= 0) {
            throw new \RuntimeException('fund_document_operator_forbidden');
        }
        return Db::transaction(function () use ($scope, $actor, $id) {
            $query = Db::name('store_fund_document')->where('id', $id)->lock(true);
            $this->applyScope($query, $scope);
            $row = (array)$query->find();
            if (!$row) throw new \RuntimeException('fund_document_not_found');
            // 门店范围已由查询强制限定；编辑或审核授权不能代替独立的删除授权。
            if (($actor['can_delete_store_documents'] ?? false) !== true) {
                $this->assertDocumentOperator($row, $actor);
            }
            // 重复删除返回同一终态；已审核单必须先按既有规则撤销，不能直接删除。
            if ($row['document_status'] !== 'DELETED') {
                if ($row['document_status'] !== 'DRAFT') throw new \RuntimeException('fund_document_delete_requires_draft');
                Db::name('store_fund_document')->where('id', $id)->update([
                    'document_status' => 'DELETED', 'audit_revision' => (int)$row['audit_revision'] + 1,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
            }
            return ['id' => $id, 'deleted' => true];
        });
    }

    public function reverse(array $scope, array $actor, int $id): array
    {
        return Db::transaction(function () use($scope,$actor,$id) {
            $originQuery=Db::name('store_fund_document')->where('id',$id)->lock(true); $this->applyScope($originQuery,$scope); $origin=(array)$originQuery->find();
            if (!$origin) throw new \RuntimeException('fund_document_not_found');
            if ($origin['document_status']!=='APPROVED') throw new \RuntimeException('fund_document_reverse_requires_audited');
            $this->assertDocumentOperator($origin,$actor);
            if (Db::name('store_fund_document')->where('source_document_id',$id)->find()) throw new \RuntimeException('fund_document_already_reversed');
            $origin['lines']=Db::name('store_fund_document_line')->where('document_id',$id)->order('line_no')->select()->toArray();
            $now=date('Y-m-d H:i:s'); $newId=(int)Db::name('store_fund_document')->insertGetId(['document_no'=>$this->documentNo(),'store_id'=>$origin['store_id'],'business_date'=>$origin['business_date'],'direction'=>$origin['direction']==='INCOME'?'EXPENSE':'INCOME','document_status'=>'APPROVED','summary'=>'冲销 '.$origin['document_no'],'remark'=>'','attachments_json'=>'[]','source_document_id'=>$id,'created_by_type'=>$actor['type'],'created_by_id'=>$actor['id'],'created_by_name_snapshot'=>$actor['name'],'audited_by_type'=>$actor['type'],'audited_by_id'=>$actor['id'],'audited_at'=>$now,'audit_revision'=>1,'created_at'=>$now,'updated_at'=>$now]);
            foreach ($origin['lines'] as $line) $this->insertLine($newId,(int)$line['line_no'],['subject_id'=>(int)$line['subject_id'],'amount_cents'=>(int)$line['amount_cents'],'summary'=>$line['summary'],'remark'=>$line['remark']],$now);
            return $this->detail($scope,$newId);
        });
    }

    public function ledger(array $scope, array $filters, bool $paginate = true): array
    {
        $query=Db::name('store_fund_document_line')->alias('l')->join('store_fund_document d','d.id=l.document_id')->leftJoin('system_store s','s.id=d.store_id')->where('d.document_status','APPROVED')->field('d.id,d.document_no,d.store_id,s.name store_name,d.business_date,d.direction,d.summary document_summary,l.*');
        $openingBalance=$this->openingBalance($scope,$filters);
        $this->applyScope($query,$scope); $this->applyFilters($query,$filters, true);
        $rows=$query->order('d.business_date asc,d.id asc,l.line_no asc')->select()->toArray(); $balance=$openingBalance;
        foreach ($rows as &$row) { $signed=$row['direction']==='INCOME'?(int)$row['amount_cents']:-(int)$row['amount_cents']; $balance+=$signed; $row['signed_amount_cents']=$signed; $row['balance_cents']=$balance; $row['signed_amount']=$this->amount($signed); $row['balance']=$this->amount($balance); }
        unset($row);
        $count=count($rows); $page=max(1,(int)($filters['page']??1)); $limit=self::LEDGER_PAGE_SIZE;
        // 导出读取完整授权结果；页面接口只返回当前页，但每条余额仍基于完整时间序列。
        if ($paginate) $rows=array_slice($rows,($page-1)*$limit,$limit);
        return ['list'=>$rows,'count'=>$count,'page'=>$page,'limit'=>$limit,'opening_balance_cents'=>$openingBalance,'opening_balance'=>$this->amount($openingBalance),'current_balance_cents'=>$balance,'current_balance'=>$this->amount($balance)];
    }

    public function report(array $scope, array $filters): array
    {
        $query=Db::name('store_fund_document_line')->alias('l')->join('store_fund_document d','d.id=l.document_id')->leftJoin('system_store s','s.id=d.store_id')->where('d.document_status','APPROVED')->field("d.store_id,s.name store_name,l.subject_id,l.subject_name_snapshot,l.subject_type_snapshot,SUM(CASE WHEN d.direction = 'INCOME' THEN l.amount_cents ELSE 0 END) income_amount_cents,SUM(CASE WHEN d.direction = 'EXPENSE' THEN l.amount_cents ELSE 0 END) expense_amount_cents,COUNT(*) line_count");
        $this->applyScope($query,$scope); $this->applyFilters($query,$filters, true); $rows=$query->group('d.store_id,s.name,l.subject_id,l.subject_name_snapshot,l.subject_type_snapshot')->order('l.subject_type_snapshot asc,l.subject_id asc')->select()->toArray();
        $income=0; $expense=0; $count=0;
        foreach ($rows as &$row) {
            $row['income_amount_cents']=(int)$row['income_amount_cents'];
            $row['expense_amount_cents']=(int)$row['expense_amount_cents'];
            $row['net_amount_cents']=$row['income_amount_cents']-$row['expense_amount_cents'];
            $row['income_amount']=$this->amount($row['income_amount_cents']);
            $row['expense_amount']=$this->amount($row['expense_amount_cents']);
            $row['net_amount']=$this->amount($row['net_amount_cents']);
            $income+=$row['income_amount_cents']; $expense+=$row['expense_amount_cents']; $count+=(int)$row['line_count'];
        }
        return ['list'=>$rows,'summary'=>['income_amount_cents'=>$income,'expense_amount_cents'=>$expense,'net_amount_cents'=>$income-$expense,'income_amount'=>$this->amount($income),'expense_amount'=>$this->amount($expense),'net_amount'=>$this->amount($income-$expense),'line_count'=>$count]];
    }

    /** Exports reuse the exact same authorized query projections as the visible tables. */
    public function exportLedger(array $scope, array $filters): array
    {
        $result=$this->ledger($scope,$filters,false);
        $withStore=true;
        $records=[[ 'business_date'=>(string)($filters['date_from']??''), 'document_no'=>'-', 'store_name'=>'-', 'subject_name'=>'-', 'direction'=>'期初', 'amount'=>'-', 'balance'=>$result['opening_balance'], 'summary'=>'-' ]];
        foreach ($result['list'] as $row) $records[]=[
            'business_date'=>$row['business_date'],'document_no'=>$row['document_no'],'store_name'=>$row['store_name']??'',
            'subject_name'=>$row['subject_name_snapshot'],'direction'=>$row['direction']==='INCOME'?'收入':'支出',
            'amount'=>$row['signed_amount'],'balance'=>$row['balance'],'summary'=>$row['summary']?:$row['document_summary'],
        ];
        return $this->writeXlsx(
            $this->ledgerColumns($withStore),
            $records,
            ['business_date'=>'合计','document_no'=>'-','store_name'=>'-','subject_name'=>'-','direction'=>'-','amount'=>'-','balance'=>$result['current_balance'],'summary'=>'-'],
            '费用收支台账'
        );
    }

    public function exportReport(array $scope, array $filters): array
    {
        $result=$this->report($scope,$filters); $records=[]; $withStore=true;
        foreach ($result['list'] as $row) $records[]=[
            'store_name'=>$row['store_name']??'','subject_name'=>$row['subject_name_snapshot'],'subject_type'=>$row['subject_type_snapshot']==='SPECIAL'?'专项':'普通费用',
            'income_amount'=>$row['income_amount'],'expense_amount'=>$row['expense_amount'],'net_amount'=>$row['net_amount'],'line_count'=>$row['line_count'],
        ];
        return $this->writeXlsx(
            $this->reportColumns($withStore),
            $records,
            ['store_name'=>'-','subject_name'=>'合计','subject_type'=>'-','income_amount'=>$result['summary']['income_amount'],'expense_amount'=>$result['summary']['expense_amount'],'net_amount'=>$result['summary']['net_amount'],'line_count'=>$result['summary']['line_count']],
            '费用统计与专项报表'
        );
    }

    private function writeXlsx(array $columns,array $records,array $summary,string $title): array
    {
        $rows=[]; foreach (array_merge($records, [$summary]) as $record) $rows[]=array_map(fn(array $column)=>$record[$column['key']]??'', $columns);
        $timestamp=date('YmdHis');
        $fileKey='fund_'.($title==='费用收支台账'?'ledger':'report').'_'.$timestamp.'_'.random_int(1000,9999);
        $files=app()->make(ExportServices::class)->export(array_column($columns,'label'),[$title,$title,'生成时间：'.date('Y-m-d H:i:s')],$rows,$fileKey,'xlsx');
        if (!$files) throw new \RuntimeException('fund_export_file_create_failed');
        return ['file_key'=>$fileKey,'filename'=>$title.'-'.$timestamp.'.xlsx'];
    }

    public function exportFilePath(string $fileKey): string
    {
        $fileKey=basename($fileKey);
        if (!preg_match('/^fund_(ledger|report)_([0-9]{14})_([0-9]{4})$/',$fileKey,$matches)) throw new \InvalidArgumentException('fund_export_file_invalid');
        $path=public_path().'phpExcel'.DIRECTORY_SEPARATOR.$fileKey.'.xlsx';
        if (!is_file($path)) throw new \RuntimeException('fund_export_file_not_found');
        return $path;
    }

    public function exportDownloadName(string $fileKey): string
    {
        if (!preg_match('/^fund_(ledger|report)_([0-9]{14})_[0-9]{4}$/',basename($fileKey),$matches)) throw new \InvalidArgumentException('fund_export_file_invalid');
        return ($matches[1]==='ledger'?'费用收支台账':'费用统计与专项报表').'-'.$matches[2].'.xlsx';
    }

    private function ledgerColumns(bool $withStore): array { $columns=[['key'=>'business_date','label'=>'日期'],['key'=>'document_no','label'=>'单号']]; if($withStore)$columns[]=['key'=>'store_name','label'=>'门店']; return array_merge($columns,[['key'=>'subject_name','label'=>'科目'],['key'=>'direction','label'=>'方向'],['key'=>'amount','label'=>'金额'],['key'=>'balance','label'=>'余额'],['key'=>'summary','label'=>'摘要']]); }
    private function reportColumns(bool $withStore): array { $columns=[]; if($withStore)$columns[]=['key'=>'store_name','label'=>'门店']; return array_merge($columns,[['key'=>'subject_name','label'=>'科目'],['key'=>'subject_type','label'=>'类型'],['key'=>'income_amount','label'=>'收入'],['key'=>'expense_amount','label'=>'支出'],['key'=>'net_amount','label'=>'净额'],['key'=>'line_count','label'=>'明细数']]); }

    private function insertLine(int $documentId,int $lineNo,array $line,string $now): void { $subject=(array)Db::name('store_fund_subject')->where('id',(int)($line['subject_id']??0))->where('is_enabled',1)->find(); $amount=(int)($line['amount_cents']??0); if(!$subject||$amount<=0) throw new \InvalidArgumentException('fund_document_line_invalid'); Db::name('store_fund_document_line')->insert(['document_id'=>$documentId,'line_no'=>$lineNo,'subject_id'=>$subject['id'],'subject_code_snapshot'=>$subject['subject_code'],'subject_name_snapshot'=>$subject['subject_name'],'subject_type_snapshot'=>$subject['subject_type'],'amount_cents'=>$amount,'summary'=>trim((string)($line['summary']??'')),'remark'=>trim((string)($line['remark']??'')),'created_at'=>$now,'updated_at'=>$now]); }
    private function openingBalance(array $scope,array $filters): int { if (empty($filters['date_from'])) return 0; $query=Db::name('store_fund_document_line')->alias('l')->join('store_fund_document d','d.id=l.document_id')->where('d.document_status','APPROVED')->field('d.direction,l.amount_cents'); $this->applyScope($query,$scope); $this->applyFilters($query,$filters,true,false); $query->where('d.business_date','<',$filters['date_from']); $balance=0; foreach($query->select()->toArray() as $row) $balance+=($row['direction']==='INCOME'?1:-1)*(int)$row['amount_cents']; return $balance; }
    private function applyScope($query,array $scope): void { if(($scope['mode']??'')==='store') $query->where('store_id',(int)$scope['store_id']); elseif(!empty($scope['store_ids'])) $query->whereIn('store_id',$scope['store_ids']); }
    private function scopeStoreId(array $scope,array $input): int { $id=($scope['mode']??'')==='store'?(int)$scope['store_id']:(int)($input['store_id']??0); if($id<=0||(!empty($scope['store_ids'])&&!in_array($id,$scope['store_ids'],true))) throw new \InvalidArgumentException('fund_store_scope_invalid'); return $id; }
    private function applyFilters($query,array $filters,bool $hasLineAlias,bool $includeDates=true): void { foreach(['store_id'=>'store_id','direction'=>'direction','document_status'=>'document_status'] as $key=>$column) if(!empty($filters[$key])) $query->where($column,$filters[$key]); if($hasLineAlias&&!empty($filters['subject_id'])) $query->where('l.subject_id',(int)$filters['subject_id']); if($hasLineAlias&&in_array(($filters['subject_type']??''),['NORMAL','SPECIAL'],true)) $query->where('l.subject_type_snapshot',$filters['subject_type']); if($includeDates&&!empty($filters['date_from'])) $query->where('business_date','>=',$filters['date_from']); if($includeDates&&!empty($filters['date_to'])) $query->where('business_date','<=',$filters['date_to']); }
    private function assertDocumentOperator(array $document,array $actor): void { if((string)$document['created_by_type']!==(string)$actor['type'] || (int)$document['created_by_id']!==(int)$actor['id']) throw new \RuntimeException('fund_document_operator_forbidden'); }
    private function documentNo(): string { return 'F'.date('YmdHis').str_pad((string)random_int(0,9999),4,'0',STR_PAD_LEFT); }
    private function validDate(string $date): bool { $d=\DateTime::createFromFormat('Y-m-d',$date); return $d&&$d->format('Y-m-d')===$date; }
    private function amount(int $cents): string { $sign=$cents<0?'-':''; $cents=abs($cents); return $sign.intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT); }
}
