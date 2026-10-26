#!/usr/bin/env python3
"""Extract unchanged, already-verified ordinary orders; no database writes.

This first delivery deliberately leaves card migrations for later. Every graph
is copied byte-for-byte from the frozen parent, preserving payment, service,
reversal and archive relationships. Fresh source evidence must still match the
parent evidence; this script cannot waive source conflicts or PM acceptance.
"""
import argparse, collections, gzip, hashlib, json, pathlib, shutil
p=argparse.ArgumentParser(description=__doc__)
p.add_argument('parent',type=pathlib.Path);p.add_argument('work',type=pathlib.Path)
p.add_argument('--verify-current',action='store_true');a=p.parse_args();b=a.parent;w=a.work
H=lambda p:hashlib.sha256(p.read_bytes()).hexdigest()
J=lambda p:json.loads(p.read_text())
def save(n,v): (w/n).write_text(json.dumps(v,ensure_ascii=False,indent=2)+'\n')
def stream(p):
    with gzip.open(p,'rt') as f:
        for line in f: yield json.loads(line)
manifest=J(b/'010-history-execution-manifest.json')
assert H(b/'010-history-execution-manifest.json')=='da3df97da1cf9708114d5fc9e7f3ba77a5698131a03345c3e8d05abb70a6396e'
for n,h in manifest['inputs'].items(): assert H(b/n)==h,n
assert J(b/'full-rehearsal.json')['passed'] and J(b/'full-rehearsal-audit-verified.json')['passed']
if not a.verify_current:
    w.mkdir(parents=True,exist_ok=False); counts=collections.Counter(); stores=collections.Counter(); ids=[]
    with gzip.open(b/'010-history-assembled-order-graphs.ndjson.gz','rb') as src, gzip.open(w/'010-history-assembled-order-graphs.ndjson.gz','wb') as dst:
        for line in src:
            g=json.loads(line)
            if g['mode']!='ordinary':continue
            assert not g['businessMutations']
            assert not g['insertTables'].get('cashier_v3_card_purchase_receipt')
            assert not g['insertTables'].get('store_order')
            assert not g['insertTables'].get('migration_entitlement_scope')
            assert g['readyForApply'] is False and all(g['invariants'].values())
            dst.write(line);ids.append(g['sourceOrderId']); stores[g['storeId']]+=1
            counts.update({t:len(rows)for t,rows in g['insertTables'].items()});counts['oldCopiesToArchive']+=len(g['archiveAfterSuccessfulGraph'])
    assert len(ids)==26323 and len(set(ids))==len(ids) and len(stores)==10
    proof={'passed':True,'readyForApply':False,'productionWrites':0,'graphsVerified':len(ids),'stores':dict(stores),'tableCounts':dict(counts),'graphSHA256':H(w/'010-history-assembled-order-graphs.ndjson.gz'),'parentManifestSHA256':H(b/'010-history-execution-manifest.json'),'verification':'byte-for-byte ordinary subset of independently verified and fully rehearsed parent; no field recomputation','parentIndependentVerificationSHA256':H(b/'010-history-assembled-order-graphs-verification.json'),'parentFullRehearsalSHA256':H(b/'full-rehearsal.json'),'holderMutations':0,'applicationCodeChanged':False,'foundationChanged':False}
    save('010-history-assembled-order-graphs-verification.json',proof)
    save('010-history-assembled-order-graphs-summary.json',{'readyForApply':False,'productionWrites':0,'counts':{'graphs':len(ids),'ordinary':len(ids),**dict(counts)},'stores':dict(stores),'remainingSourceSales':47289-len(ids),'scope':'first unchanged ordinary subset; remaining sales are not claimed complete'})
    uq=J(b/'010-history-graph-unique-keys-verification.json');assert uq['passed']
    save('010-history-graph-unique-keys-verification.json',{'passed':True,'productionWrites':0,'graphs':len(ids),'basis':'unchanged subset of unique parent graph set','parentEvidenceSHA256':H(b/'010-history-graph-unique-keys-verification.json'),'graphSHA256':proof['graphSHA256']})
    for n in ['010-history-graph-current-write-constraints.json','010-history-graph-current-dependencies.json','010-history-graph-current-derived-constraints.json','010-history-balance-allocation-plans.ndjson.gz','010-history-balance-allocation-independent-verification.json','010-history-batch-runtime-after.txt']:shutil.copy2(b/n,w/n)
    print(json.dumps({'graphs':len(ids),'stores':dict(stores),'insertRows':sum(v for k,v in counts.items()if k!='oldCopiesToArchive'),'archives':counts['oldCopiesToArchive']}))
else:
    scope=json.loads(gzip.decompress((w/'010-history-graph-preflight-scope.json.gz').read_bytes()))
    wanted={k:set(scope[n])for k,n in [('order','archiveIds'),('holder','holderIds'),('writeoff','writeoffIds')]};sales=set(scope['salesIds']);expected={};baseline={};current={};seen=collections.Counter();protected=[];meta=None;complete=None
    for e in stream(b/'010-history-graph-current-preflight.ndjson.gz'):
        k,r=e['kind'],e['row']
        if k in ['order','detail','holder','writeoff']:
            v=r['value'];keep=int(v['oid'])in sales if k=='detail'else int(v['id'])in wanted[k]
            if keep:expected[(k,int(v['id']))]=r['sha256']
        elif k in ['schema','staffIdentity','foundation']:baseline[(k,str(r.get('table',r.get('id','all'))))]=r
    table={'order':'store_order','detail':'store_order_cart_info','holder':'user_card_holder','writeoff':'store_order_writeoff'};guards=[];seenrows=set()
    for e in stream(w/'010-history-graph-current-preflight.ndjson.gz'):
        k,r=e['kind'],e['row'];assert complete is None;seen[k]+=1
        if k in table:
            v=r['value'];key=(k,int(v['id']));assert key not in seenrows;seenrows.add(key)
            h=hashlib.sha256(json.dumps(v,ensure_ascii=False,sort_keys=True,separators=(',',':')).encode()).hexdigest()
            assert h==r['sha256']==expected.get(key),('source changed',key)
            guards.append({'table':table[k],'id':key[1],'sha256':h})
        elif k in ['schema','staffIdentity','foundation']:current[(k,str(r.get('table',r.get('id','all'))))]=r
        elif k=='collision':raise AssertionError(('collision',r))
        elif k=='protected':protected.append(r)
        elif k=='metadata':
            meta=r;assert r['database']=='mohe_010_app' and r['mode']=='read-only' and r['productionWrites']==0
            assert r['graphsSHA256']==H(w/'010-history-assembled-order-graphs.ndjson.gz') and r['scopeSHA256']==H(w/'010-history-graph-preflight-scope.json.gz')
        elif k=='complete':complete=r
    assert complete and meta and seenrows==set(expected) and current==baseline
    for k,n in complete['counts'].items():assert seen[k]==n,(k,n,seen[k])
    assert len(protected)==11
    name='010-history-graph-verified-source-guards.ndjson.gz'
    with gzip.open(w/name,'wt')as f:
        for g in guards:f.write(json.dumps(g,separators=(',',':'))+'\n')
    save('010-history-graph-current-preflight-verification.json',{'passed':True,'readyForApply':False,'productionWrites':0,'graphs':len(sales),'rowsGuarded':len(guards),'sourceMetadata':meta,'protectedTables':protected,'guardSHA256':H(w/name),'issues':[],'basis':'fresh runtime rows exactly match unchanged parent subset; all schemas, staff identities and foundation match','parentManifestSHA256':H(b/'010-history-execution-manifest.json')})
    print(json.dumps({'passed':True,'graphs':len(sales),'rowsGuarded':len(guards)}))
