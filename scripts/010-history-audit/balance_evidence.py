"""只读核对原快照扣款拆分；不生成可写入SQL，不将估算当成原始事实。"""
import csv,json,sys
from collections import defaultdict,Counter
from decimal import Decimal as D
ledger=list(csv.DictReader(open(sys.argv[1]),delimiter='\t'))
orders=list(csv.DictReader(open(sys.argv[2]),delimiter='\t'))
# 台账自增ID表示写入顺序；业务add_time允许补录，不能当成余额变动顺序。
ledger.sort(key=lambda r:(int(r['uid']),int(r['id'])))
prior={}; evidence={}; by_order=defaultdict(list)
for r in ledger:
    p=prior.get(r['uid']); prior[r['uid']]=r
    if r['type']!='pay_product':continue
    by_order[(r['uid'],r['link_id'])].append(r)
    if p is None:continue
    # 原系统存在固定余额差额：只要差额前后不变、两个分项与总余额变动同时闭合，仍可保留扣款拆分证据。
    ben=D(p['ben_money'])-D(r['ben_money']); bonus=D(p['give_money'])-D(r['give_money'])
    # 同会员连续台账前后变化、支出方向、总额均须闭合；不依赖今天的余额。
    if r['pm']=='0' and min(ben,bonus)>=0 and ben+bonus==D(r['number'])==D(p['balance'])-D(r['balance']):
        evidence[r['id']]={'principal':str(ben),'bonus':str(bonus),'beforeLedgerId':p['id'],'ledgerId':r['id']}
counts=Counter();exceptions=[]; matched=[]
for o in orders:
    rows=by_order[(o['uid'],o['id'])]
    if not rows:reason='missing_ledger'
    elif len(rows)!=1:reason='multiple_ledgers'
    elif D(rows[0]['number'])!=D(o['yue_pay_price']):reason='amount_mismatch'
    elif rows[0]['id'] not in evidence:reason='unclosed_transition'
    else:
        counts['closed_transition']+=1;matched.append({'orderId':o['id'],**evidence[rows[0]['id']]});continue
    counts[reason]+=1;exceptions.append({'orderId':o['id'],'memberId':o['uid'],'amount':o['yue_pay_price'],'refundStatus':o['refund_status'],'reason':reason})
result={'source':'007一致性快照，只读','orderCount':len(orders),'ledgerCount':len(ledger),'counts':dict(counts),'evidence':matched,'exceptions':exceptions,'limitations':['连续台账只提供拆分证据，尚需排除倒录时间或缺失台账造成的误闭合','不写资金、不改变本金赠金、不批准退款或作废','Direct order-ID misses require parent, transfer and card-upgrade lineage before any wallet allocation; unresolved real wallet splits use the approved bonus fallback only in migration data.']}
json.dump(result,open(sys.argv[3],'w'),ensure_ascii=False,indent=2)
print(json.dumps({k:v for k,v in result.items() if k not in ('evidence','exceptions')},ensure_ascii=False))
