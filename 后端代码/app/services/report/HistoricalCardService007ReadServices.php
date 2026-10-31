<?php
declare(strict_types=1);
namespace app\services\report;
use think\facade\Db;

/** Read-only, explicitly labelled legacy service projection; never creates V3 facts or changes rights. */
final class HistoricalCardService007ReadServices
{
    private $loader;
    public function __construct(?callable $loader=null){$this->loader=$loader?:[$this,'load'];}

    /** Components have already been authorized by the caller's current-member-store scope.
     * Service store is provenance, never an extra filter on lifetime used amounts.
     */
    public function readComponents(array $components):array
    {
        $by=[];
        foreach($components as$c){
            foreach(['project_detail_id','card_holder_id','origin_order_id','member_id','store_id']as$k)
                if(!isset($c[$k])||!ctype_digit((string)$c[$k])||(int)$c[$k]<1)throw new \InvalidArgumentException('历史组件授权身份缺失');
            $id=(int)$c['project_detail_id'];if(isset($by[$id])&&$by[$id]!=$c)throw new \LogicException('组件授权身份冲突');$by[$id]=$c;
        }
        if(!$by)return ['complete'=>true,'rows'=>[],'gaps'=>[],'excluded'=>[]];
        return $this->project(($this->loader)(['components'=>$by]),$by);
    }

    /** Authorized service-store range; only the approved import date rule assigns historical business dates. */
    public function readServices(array $stores,array $range):array
    {
        foreach($stores as$s)if(!is_int($s)||$s<1)throw new \InvalidArgumentException('服务门店范围无效');
        foreach(['start','end']as$k)if(!isset($range[$k])||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$range[$k])||date('Y-m-d',strtotime($range[$k]))!==$range[$k])throw new \InvalidArgumentException('业务日期范围无效');
        if($range['start']>$range['end'])throw new \InvalidArgumentException('业务日期倒置');
        if(!$stores)return ['complete'=>true,'rows'=>[],'gaps'=>[],'excluded'=>[]];
        // Per-store source batches keep the indexed legacy/V3 identity comparison below the query timeout.
        $source=[];foreach(array_values(array_unique($stores))as$store)$source=array_merge($source,($this->loader)(['stores'=>[$store],'range'=>$range]));
        return $this->project($source,[]);
    }

    /** Every rejected original remains auditable by ID; missing data must not become zero. */
    private function project(array $source,array $components):array
    {
        $rows=[];$gaps=[];$excluded=[];$seen=[];
        foreach($source as$r){
            // 007 service consumption depends on the completed writeoff, not today's holder/order state.
            // Component balances additionally require the exact current authorized entitlement chain.
            $id=(int)$r['id'];$fail=null;
            if(isset($seen[$id])){$gaps[]=['source_writeoff_id'=>$id,'project_detail_id'=>(int)$r['order_cart_id'],'code'=>'HISTORICAL_CARD_DUPLICATE_SOURCE'];continue;}$seen[$id]=true;
            if((int)$r['mapped_count']>0){
                if((int)$r['mapped_count']!==1||(int)$r['mapped_identity_ok']!==1)$gaps[]=['source_writeoff_id'=>$id,'project_detail_id'=>(int)$r['order_cart_id'],'code'=>'HISTORICAL_CARD_V3_MAPPING_CONFLICT'];
                elseif((int)$r['status']!==0||(int)$r['voided_count']>0)$excluded[]=['source_writeoff_id'=>$id,'reason'=>'canceled_or_voided'];
                elseif($components&&((int)($r['mapped_writeoff_count']??0)!==1||(int)($r['mapped_writeoff_identity_ok']??0)!==1))$gaps[]=['source_writeoff_id'=>$id,'project_detail_id'=>(int)$r['order_cart_id'],'code'=>'HISTORICAL_CARD_WRITE_OFF_MAPPING_CONFLICT'];
                else $excluded[]=['source_writeoff_id'=>$id,'reason'=>'already_mapped'];
                continue;
            }
            if((int)$r['status']!==0||(int)$r['voided_count']>0){$excluded[]=['source_writeoff_id'=>$id,'reason'=>'canceled_or_voided'];continue;}
            if((int)$r['type']!==1)$fail='SOURCE_TYPE';
            elseif($components&&((int)$r['order_found']!==1||(int)$r['detail_found']!==1))$fail='ORDER_OR_DETAIL_MISSING';
            elseif($components&&((int)$r['order_uid']!==(int)$r['uid']||(int)$r['detail_oid']!==(int)$r['oid']||(int)$r['detail_product_id']!==(int)$r['product_id']))$fail='ORIGINAL_IDENTITY';
            elseif($components&&((int)$r['holder_count']!==1||(int)$r['holder_uid']!==(int)$r['uid']))$fail='HOLDER_IDENTITY';
            elseif($components&&((int)$r['order_deleted']!==0||(int)$r['order_paid']!==1||!in_array((int)$r['order_refund'],[0,3],true)))$fail='ORDER_STATE';
            elseif($components&&((int)$r['order_archived']!==0&&(int)$r['archive_proven']!==1))$fail='ARCHIVE_UNPROVEN';
            elseif(!in_array((string)$r['service_object'],['本人','self','朋友','friend'],true))$fail='SERVICE_OBJECT';
            elseif((int)$r['add_time']<=0)$fail='BUSINESS_DATE_MISSING';
            elseif(!preg_match('/^\d+(?:\.\d{1,2}0*)?$/D',(string)$r['writeoff_price']))$fail='AMOUNT_INVALID';
            elseif(!is_numeric($r['writeoff_num'])||(float)$r['writeoff_num']<=0)$fail='QUANTITY_INVALID';
            $component=$components[(int)$r['order_cart_id']]??null;
            if(!$fail&&$components&&(!$component||(int)$component['origin_order_id']!==(int)$r['oid']||(int)$component['member_id']!==(int)$r['uid']||(int)$component['card_holder_id']!==(int)$r['holder_id']))$fail='COMPONENT_SCOPE';
            if($fail){$gaps[]=['source_writeoff_id'=>$id,'project_detail_id'=>(int)$r['order_cart_id'],'code'=>'HISTORICAL_CARD_'.$fail,'holder_count'=>(int)$r['holder_count'],'holder_member_matches'=>(int)$r['holder_uid']===(int)$r['uid'],'order_paid'=>(int)$r['order_paid'],'order_deleted'=>(int)$r['order_deleted'],'order_refund'=>(int)$r['order_refund']];continue;}
            // Old fractional yuan are preserved in cents; this is not a new cashier save.
            $p=explode('.',(string)$r['writeoff_price']);$amount=(int)$p[0]*100+(int)substr(str_pad($p[1]??'',2,'0'),0,2);
            $date=(new \DateTimeImmutable('@'.(int)$r['add_time']))->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('Y-m-d');
            $rows[]=['source_kind'=>'historical_007_card_service','source_writeoff_id'=>$id,'project_detail_id'=>(int)$r['order_cart_id'],
                'card_holder_id'=>(int)$r['holder_id'],'origin_order_id'=>(int)$r['oid'],'member_id'=>(int)$r['uid'],
                'service_store_id'=>(int)$r['relation_id'],'project_id'=>(int)$r['product_id'],'business_date'=>$date,
                'business_date_rule'=>'accepted_007_import_service_time_Asia_Shanghai','occurred_at'=>(int)$r['add_time'],
                'writeoff_code'=>(string)($r['writeoff_code']??''),'operator_id'=>(int)($r['staff_id']??0),'ordering_store_id'=>(int)($r['order_store_id']??0),'quantity'=>(string)$r['writeoff_num'],'amount_cents'=>$amount,'original_service_object'=>(string)$r['service_object'],'service_object'=>in_array((string)$r['service_object'],['朋友','friend'],true)?'friend':'self',
                'project_snapshot_json'=>(string)$r['cart_info'],'personnel_source'=>['table'=>'staff_yeji','type'=>3,'link_id'=>$id]];
        }
        return ['complete'=>!$gaps,'rows'=>$rows,'gaps'=>$gaps,'excluded'=>$excluded];
    }

    /** Build explicitly historical metric-shaped rows for shared report projections, never persisted facts. */
    public function reportProjection(array $result,int $employee=0):array
    {
        $old=$result['rows']??[];$people=$this->personnel($old);$result['gaps']=array_merge($result['gaps']??[],$people['gaps']);
        $participants=[];foreach($people['rows']as$p)$participants[$p['source_writeoff_id']][]=$p;
        $links=[];foreach($old as$i=>$r)$links[$i]=['store_id'=>$r['service_store_id'],'product_id'=>$r['project_id']];
        $categories=(new StoreReportCurrentProductCategoryServices())->resolveVerifiedFactItems($links);
        $lookup=static function($table,$field,$ids){$out=[];foreach(array_chunk(array_values(array_unique(array_filter($ids))),300)as$chunk)foreach(Db::name($table)->whereIn($field,$chunk)->select()->toArray()as$row)$out[(int)$row[$field]]=$row;return$out;};
        $products=$lookup('store_product','id',array_column($old,'project_id'));
        $members=$lookup('user','uid',array_column($old,'member_id'));
        $operators=$lookup('system_store_staff','id',array_column($old,'operator_id'));
        $stores=$lookup('system_store','id',array_merge(array_column($old,'service_store_id'),array_column($old,'ordering_store_id')));
        $services=[];$amounts=[];$sources=['phones'=>[],'store_names'=>[],'ordering_store_ids'=>[]];
        foreach($stores as$id=>$store)$sources['store_names'][$id]=(string)$store['name'];
        foreach($old as$i=>$r){
            $staff=$participants[$r['source_writeoff_id']]??[];
            if($employee>0&&!in_array($employee,array_column($staff,'employee_id'),true))continue;
            $id='historical:writeoff:'.$r['source_writeoff_id'];$member=$members[$r['member_id']]??[];$cat=$categories[$i]??['id'=>0,'path'=>''];
            // A physically missing catalog row may use only its own immutable cart snapshot for its name.
            // 007's LEFT JOIN yields an unclassified item; never invent or map a replacement catalog ID.
            $snapshot=json_decode($r['project_snapshot_json'],true);$original=$snapshot['productInfo']??[];
            $name=$products[$r['project_id']]['store_name']??'';
            if($name===''&&(int)($original['id']??0)===$r['project_id'])$name=(string)($original['store_name']??'');
            $unclassified=(int)$cat['id']===0&&$name!=='';
            if($unclassified)$cat['path']='未分类';
            $craftsmen=[];foreach($staff as$p)$craftsmen[]=['employeeId'=>$p['employee_id'],'name'=>$p['employee_name_snapshot'],'isPointCustomer'=>$p['is_point_customer']];
            $row=array_merge($r,['id'=>$r['source_writeoff_id'],'service_fact_id'=>$id,'source_document_type'=>'legacy_writeoff','source_document_id'=>(string)$r['source_writeoff_id'],
                'store_id'=>$r['service_store_id'],'store_name_snapshot'=>$stores[$r['service_store_id']]['name']??'',
                'checkout_request_id'=>$id,'source_line_id'=>$id,'document_id'=>$id,'document_no_snapshot'=>$r['writeoff_code'],
                'member_name_snapshot'=>$member['real_name']??$member['nickname']??'',
                'project_name_snapshot'=>$name,'category_unclassified'=>$unclassified,
                'project_category_name_snapshot'=>$cat['path'],'category_id'=>(int)$cat['id'],'category_path'=>$cat['path'],
                'operator_name_snapshot'=>$operators[$r['operator_id']]['staff_name']??'',
                'craftsmen_snapshot_json'=>json_encode($craftsmen,JSON_UNESCAPED_UNICODE)]);
            $services[]=$row;$amounts[]=array_merge($row,['fact_id'=>$id.':amount']);
            $sources['phones'][$r['member_id']]=$member['phone']??'';
            $sources['ordering_store_ids'][$id]=$r['ordering_store_id'];
        }
        return ['services'=>$services,'amounts'=>$amounts,'sources'=>$sources,'gaps'=>$result['gaps'],'complete'=>!$result['gaps']];
    }

    /** Original labor rows are linked by exact writeoff, order, project and executing store.
     * Current employment identity follows the user's explicit current-employment report rule;
     * recorded labor amount/name remain original evidence, not a reconstructed V3 allocation.
     */
    public function personnel(array $services):array
    {
        $by=[];foreach($services as$r){if(($r['source_kind']??'')!=='historical_007_card_service')throw new \InvalidArgumentException('历史服务来源标记错误');$by[(int)$r['source_writeoff_id']]=$r;}
        $rows=[];$gaps=[];
        foreach(array_chunk(array_keys($by),300)as$ids){
            $p=Db::name('staff_yeji')->where('type',3)->whereIn('link_id',$ids)->where('status',0)->select()->toArray();
            $staffIds=array_values(array_unique(array_map(static function($x){return(int)$x['staff_id'];},$p)));$staff=[];
            if($staffIds)foreach(Db::name('system_store_staff')->whereIn('id',$staffIds)->select()->toArray()as$x)$staff[(int)$x['id']]=$x;
            foreach($p as$x){$service=$by[(int)$x['link_id']];$who=$staff[(int)$x['staff_id']]??[];
                if((int)$x['order_id']!==$service['origin_order_id']||(int)$x['goods_id']!==$service['project_id']||(int)$x['store_id']!==$service['service_store_id']||!isset($who['employee_id'],$who['is_hezuofang'])||(int)$who['employee_id']<1||!in_array((int)$who['is_hezuofang'],[0,1],true)||!preg_match('/^\d+(?:\.\d{1,2}0*)?$/D',(string)$x['yeji'])){
                    $gaps[]=['code'=>'HISTORICAL_CARD_LABOR_IDENTITY','source_writeoff_id'=>(int)$x['link_id'],'source_labor_id'=>(int)$x['id']];continue;
                }
                $parts=explode('.',(string)$x['yeji']);$amount=(int)$parts[0]*100+(int)substr(str_pad($parts[1]??'',2,'0'),0,2);
                $rows[]=['source_kind'=>'historical_007_card_labor','source_writeoff_id'=>(int)$x['link_id'],'source_labor_id'=>(int)$x['id'],'staff_id'=>(int)$x['staff_id'],'employee_id'=>(int)$who['employee_id'],'employee_name_snapshot'=>(string)$x['staff_name'],'employee_type_snapshot'=>(int)$who['is_hezuofang']===1?'partner':'internal','amount_cents'=>$amount,'is_point_customer'=>(int)$x['is_dian']===1,'service_store_id'=>$service['service_store_id'],'business_date'=>$service['business_date']];
            }
        }
        return ['complete'=>!$gaps,'rows'=>$rows,'gaps'=>$gaps];
    }

    /** Source tables are discovered through the configured connection, with bounded exact-ID chunks. */
    private function load(array $scope):array
    {
        $tables=[];foreach(['store_order_writeoff','store_order','store_order_cart_info','user_card_holder','cashier_v3_entitlement_service_fact','cashier_v3_service_record_void_operation','cashier_v3_order_lifecycle_operation','cashier_v3_card_purchase_receipt','cashier_v3_sales_order','cashier_v3_entitlement_writeoff_fact']as$t){$name=Db::name($t)->getTable();if(!preg_match('/^[A-Za-z0-9_]+$/D',$name))throw new \LogicException('历史来源表名错误');$tables[$t]=$name;}
        extract($tables,EXTR_PREFIX_ALL,'t');
        $proof=ReportLegacyArchiveProofServices::visibilitySql('o','h',$t_cashier_v3_order_lifecycle_operation,$t_cashier_v3_card_purchase_receipt);
        $where=[];
        if(isset($scope['components']))foreach(array_chunk(array_keys($scope['components']),300)as$ids)$where[]='w.order_cart_id IN('.implode(',',$ids).')';
        else{
            $tz=new \DateTimeZone('Asia/Shanghai');$start=(new \DateTimeImmutable($scope['range']['start'],$tz))->getTimestamp();$end=(new \DateTimeImmutable($scope['range']['end'],$tz))->modify('+1 day')->getTimestamp();
            $where[]='w.relation_id IN('.implode(',',$scope['stores']).") AND ((w.add_time>={$start} AND w.add_time<{$end}) OR w.add_time<=0)";
        }
        $result=[];
        foreach($where as$filter){
            $sql="SELECT w.*,o.id IS NOT NULL order_found,o.uid order_uid,o.store_id order_store_id,o.paid order_paid,o.is_del order_deleted,o.refund_status order_refund,o.is_system_del order_archived,ci.id IS NOT NULL detail_found,ci.oid detail_oid,ci.product_id detail_product_id,ci.cart_info,"
                ."(SELECT COUNT(*) FROM {$t_user_card_holder} ch WHERE ch.oid=w.oid) holder_count,"
                ."(SELECT MIN(ch.id) FROM {$t_user_card_holder} ch WHERE ch.oid=w.oid) holder_id,"
                ."(SELECT MIN(ch.uid) FROM {$t_user_card_holder} ch WHERE ch.oid=w.oid) holder_uid,"
                ."COALESCE(m.n,0) mapped_count,COALESCE(m.store_id=w.relation_id AND m.project_id=w.product_id AND m.member_id=w.uid,0) mapped_identity_ok,"
                ."COALESCE(m.voided,0) voided_count,{$proof} archive_proven,"
                ."(SELECT COUNT(*) FROM {$t_cashier_v3_entitlement_writeoff_fact} wf WHERE wf.tenant_id='0' AND wf.checkout_request_id=m.request_id AND wf.source_line_id=m.line_id AND wf.status='effective') mapped_writeoff_count,"
                ."(SELECT COUNT(*) FROM {$t_cashier_v3_entitlement_writeoff_fact} wf WHERE wf.tenant_id='0' AND wf.checkout_request_id=m.request_id AND wf.source_line_id=m.line_id AND wf.status='effective' AND wf.source_detail_id=w.order_cart_id AND wf.origin_order_id=w.oid AND wf.member_id=w.uid AND wf.project_id=w.product_id AND wf.actual_entitlement_amount_cents=ROUND(w.writeoff_price*100) AND EXISTS(SELECT 1 FROM {$t_user_card_holder} wh WHERE wh.id=wf.holder_id AND wh.oid=w.oid AND wh.uid=w.uid)) mapped_writeoff_identity_ok "
                ."FROM {$t_store_order_writeoff} w LEFT JOIN {$t_store_order} o ON o.id=w.oid LEFT JOIN {$t_store_order_cart_info} ci ON ci.id=w.order_cart_id "
                ."LEFT JOIN {$t_cashier_v3_sales_order} h ON h.tenant_id='0' AND h.order_id=CONCAT('IMP007-',o.id,'-sale') AND h.source_document_type='legacy_store_order' AND h.source_document_id=CAST(o.id AS CHAR) AND h.store_id=o.store_id "
                ."LEFT JOIN (SELECT s.source_document_id,COUNT(*) n,MIN(s.store_id) store_id,MIN(s.project_id) project_id,MIN(s.member_id) member_id,MIN(s.checkout_request_id) request_id,MIN(s.source_line_id) line_id,"
                ."SUM(EXISTS(SELECT 1 FROM {$t_cashier_v3_service_record_void_operation} v WHERE v.tenant_id=s.tenant_id AND v.service_fact_id=s.id AND v.status='succeeded')) voided "
                ."FROM {$t_cashier_v3_entitlement_service_fact} s WHERE s.tenant_id='0' AND s.source_document_type IN('legacy_writeoff','legacy_paid_project') GROUP BY s.source_document_id) m ON m.source_document_id=w.id "
                ."WHERE {$filter} ORDER BY w.id LIMIT 50001";
            $part=Db::query($sql);if(count($part)>50000)throw new \RuntimeException('历史核销范围超过安全上限');$result=array_merge($result,$part);
        }
        return $result;
    }
}
