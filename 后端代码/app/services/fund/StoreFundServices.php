<?php
declare(strict_types=1);

namespace app\services\fund;

use app\services\other\export\ExportServices;
use think\facade\Db;

final class StoreFundServices
{
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

    public function listDocuments(array $scope, array $filters): array
    {
        $query = Db::name('store_fund_document')->alias('d')->leftJoin('system_store s','s.id=d.store_id')
            ->field('d.*,s.name as store_name');
        $this->applyScope($query, $scope); $this->applyFilters($query, $filters, false);
        if (!empty($filters['subject_id'])) $query->whereExists(function ($sub) use ($filters) { $sub->name('store_fund_document_line')->whereRaw('document_id=d.id')->where('subject_id',(int)$filters['subject_id']); });
        $page=max(1,(int)($filters['page']??1)); $limit=min(100,max(1,(int)($filters['limit']??20)));
        $count=(clone $query)->count();
        $rows=$query->order('d.business_date desc,d.id desc')->page($page,$limit)->select()->toArray();
        foreach ($rows as &$row) { $row['amount_cents']=(int)Db::name('store_fund_document_line')->where('document_id',$row['id'])->sum('amount_cents'); $row['amount']=$this->amount((int)$row['amount_cents']); }
        return ['list'=>$rows,'count'=>$count,'page'=>$page,'limit'=>$limit];
    }

    public function detail(array $scope, int $id): array
    {
        $query=Db::name('store_fund_document')->where('id',$id); $this->applyScope($query,$scope); $document=(array)$query->find();
        if (!$document) throw new \RuntimeException('fund_document_not_found');
        $document['lines']=Db::name('store_fund_document_line')->where('document_id',$id)->order('line_no')->select()->toArray();
        return $document;
    }

    public function save(array $scope, array $actor, array $input): array
    {
        $id=(int)($input['id']??0); $storeId=$this->scopeStoreId($scope,$input); $direction=strtoupper(trim((string)($input['direction']??'')));
        $date=trim((string)($input['business_date']??'')); $lines=$input['lines']??[];
        if (!in_array($direction,['INCOME','EXPENSE'],true) || !$this->validDate($date) || !is_array($lines) || !$lines) throw new \InvalidArgumentException('fund_document_input_invalid');
        return Db::transaction(function () use($id,$storeId,$direction,$date,$lines,$input,$actor,$scope) {
            $now=date('Y-m-d H:i:s');
            if ($id) {
                $existingQuery=Db::name('store_fund_document')->where('id',$id)->lock(true); $this->applyScope($existingQuery,$scope); $existing=(array)$existingQuery->find();
                if (!$existing || $existing['document_status']!=='DRAFT') throw new \RuntimeException('fund_document_not_editable');
                $this->assertDocumentOperator($existing,$actor);
            }
            else { $id=(int)Db::name('store_fund_document')->insertGetId(['document_no'=>$this->documentNo(),'store_id'=>$storeId,'business_date'=>$date,'direction'=>$direction,'document_status'=>'DRAFT','summary'=>'','remark'=>'','attachments_json'=>'[]','source_document_id'=>0,'created_by_type'=>$actor['type'],'created_by_id'=>$actor['id'],'created_by_name_snapshot'=>$actor['name'],'created_at'=>$now,'updated_at'=>$now]); }
            Db::name('store_fund_document')->where('id',$id)->update(['store_id'=>$storeId,'business_date'=>$date,'direction'=>$direction,'summary'=>trim((string)($input['summary']??'')),'remark'=>trim((string)($input['remark']??'')),'attachments_json'=>json_encode(array_values((array)($input['attachments']??[])),JSON_UNESCAPED_UNICODE),'updated_at'=>$now]);
            Db::name('store_fund_document_line')->where('document_id',$id)->delete();
            foreach ($lines as $index=>$line) $this->insertLine($id,$index+1,(array)$line,$now);
            return $this->detail(['mode'=>'platform','store_ids'=>[$storeId]],$id);
        });
    }

    public function audit(array $scope, array $actor, int $id, bool $audited): array
    {
        return Db::transaction(function () use($scope,$actor,$id,$audited) {
            $query=Db::name('store_fund_document')->where('id',$id)->lock(true); $this->applyScope($query,$scope); $row=(array)$query->find();
            if (!$row) throw new \RuntimeException('fund_document_not_found');
            $this->assertDocumentOperator($row,$actor);
            $status=$audited?'APPROVED':'DRAFT';
            Db::name('store_fund_document')->where('id',$id)->update(['document_status'=>$status,'audited_by_type'=>$audited?$actor['type']:'','audited_by_id'=>$audited?$actor['id']:0,'audited_at'=>$audited?date('Y-m-d H:i:s'):null,'audit_revision'=>(int)$row['audit_revision']+1,'updated_at'=>date('Y-m-d H:i:s')]);
            return $this->detail($scope,$id);
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

    public function ledger(array $scope, array $filters): array
    {
        $query=Db::name('store_fund_document_line')->alias('l')->join('store_fund_document d','d.id=l.document_id')->leftJoin('system_store s','s.id=d.store_id')->where('d.document_status','APPROVED')->field('d.id,d.document_no,d.store_id,s.name store_name,d.business_date,d.direction,d.summary document_summary,l.*');
        $openingBalance=$this->openingBalance($scope,$filters);
        $this->applyScope($query,$scope); $this->applyFilters($query,$filters, true);
        $rows=$query->order('d.business_date asc,d.id asc,l.line_no asc')->select()->toArray(); $balance=$openingBalance;
        foreach ($rows as &$row) { $signed=$row['direction']==='INCOME'?(int)$row['amount_cents']:-(int)$row['amount_cents']; $balance+=$signed; $row['signed_amount_cents']=$signed; $row['balance_cents']=$balance; $row['signed_amount']=$this->amount($signed); $row['balance']=$this->amount($balance); }
        return ['list'=>$rows,'opening_balance_cents'=>$openingBalance,'opening_balance'=>$this->amount($openingBalance),'current_balance_cents'=>$balance,'current_balance'=>$this->amount($balance)];
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
        $result=$this->ledger($scope,$filters);
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
