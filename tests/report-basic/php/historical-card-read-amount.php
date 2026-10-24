<?php
declare(strict_types=1);

/** Pure read-arithmetic regression. No vendor/framework boot, PDO or fixtures.
 * Run current dependencies normally; for the accepted e0 authority only, set
 * R51_CARD_AUTHORITY_MODE=e0 and inject git-show bytes as
 * R51_CARD_FROZEN_AUTHORITY_B64. Its immutable SHA is checked before evaluation;
 * no second editable business source is written. Docker PHP stdin is supported.
 */
use app\services\cashier\v3\card\CashierV3CardReadAmountServices as ReadAmount;
use app\services\cashier\v3\card\CashierV3CardComponentPurchaseAmountAuthority as Authority;
use app\services\cashier\v3\cashier\CashierV3EntitlementActualAmountAllocator as Allocator;

// Match the existing PHP 8 string predicate when running the PHP 7.4 test image.
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack,string $needle): bool { return substr($haystack,0,strlen($needle))===$needle; }
}
$backend=is_dir('/var/www/html/app')?'/var/www/html':dirname(__DIR__,3).'/后端代码';
$card=$backend.'/app/services/cashier/v3/card/';
$allocatorFile=$backend.'/app/services/cashier/v3/cashier/CashierV3EntitlementActualAmountAllocator.php';
$authorityFile=$card.'CashierV3CardComponentPurchaseAmountAuthority.php';
$helperFile=$card.'CashierV3CardReadAmountServices.php';
$memberFile=$backend.'/app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php';
$observedFiles=[$helperFile,$allocatorFile,$authorityFile,$memberFile];
$beforeHashes=array_map('hash_file',array_fill(0,count($observedFiles),'sha256'),$observedFiles);
$mode=getenv('R51_CARD_AUTHORITY_MODE')?:'current';
if (!in_array($mode,['current','e0'],true)) throw new RuntimeException('UNKNOWN_AUTHORITY_MODE');
require $allocatorFile;
if ($mode==='e0') {
    $authoritySource=base64_decode((string)getenv('R51_CARD_FROZEN_AUTHORITY_B64'),true);
    if (!is_string($authoritySource) || hash('sha256',$authoritySource)!=='db85e2685ad728d56953ef903e75db58c3968b3f0d90af9a05d005756551a47d')
        throw new RuntimeException('FROZEN_E0_AUTHORITY_HASH_REQUIRED');
    eval(substr($authoritySource,5));
} else { $authoritySource=file_get_contents($authorityFile); require $authorityFile; }
require $helperFile;
$checks=0;$helper=new ReadAmount();
function cardReadCheck(bool $ok,string $label): void
{
    global $checks;
    if (!$ok) throw new RuntimeException('CARD_READ_TEST_FAILED:'.$label);
    ++$checks;
}
/** Check values and input byte preservation, including every nested snapshot. */
function cardReadValue(array $args,string $expected,string $label): void
{
    global $helper;
    $before=serialize($args);$actual=$helper->remainingForComponent(...$args);
    cardReadCheck($actual===$expected,$label);
    cardReadCheck(serialize($args)===$before,$label.' inputs unchanged');
}
function cardReadReject(array $args,string $label): void
{
    global $helper;
    $before=serialize($args);$denied=false;
    try { $helper->remainingForComponent(...$args); } catch (InvalidArgumentException $error) { $denied=true; }
    cardReadCheck($denied,$label);
    cardReadCheck(serialize($args)===$before,$label.' rejected inputs unchanged');
}
/** Formal/whole reads must equal the original authority + allocator composition,
 * including exceptions. Never force the stricter local authority onto frozen e0.
 */
function cardReadDelegated(array $args,string $label): void
{
    global $helper;
    $before=serialize($args);[$cart,$snapshot,$sales,$rule,$total,$remaining]=$args;
    $expected=null;$expectedError=null;
    try {
        $authority=(new Authority())->resolve($cart,$snapshot,$sales,$rule);
        $allocation=$authority['allocationSnapshot'];
        $allocation['r26RefundedCents']=(int)($snapshot['r26RefundedCents']??0);
        $allocation['r26RefundRemovedTimes']=(int)($snapshot['r26RefundRemovedTimes']??0);
        $cents=$authority['purchaseAmountCents'];
        $money=intdiv($cents,100).'.'.str_pad((string)($cents%100),2,'0',STR_PAD_LEFT);
        $expected=Allocator::remainingForSnapshot($money,$total,$total-$remaining,$allocation);
    } catch (InvalidArgumentException $error) { $expectedError=get_class($error); }
    $actual=null;$actualError=null;
    try { $actual=$helper->remainingForComponent(...$args); }
    catch (InvalidArgumentException $error) { $actualError=get_class($error); }
    cardReadCheck($actual===$expected && $actualError===$expectedError,$label.' delegates exact behavior');
    cardReadCheck(serialize($args)===$before,$label.' delegated inputs unchanged');
}
// With only these three pure classes loaded, a hidden framework/DB dependency
// is an immediate failure rather than an available connection or service call.
$autoload=static function (string $class): void { throw new RuntimeException('FORBIDDEN_AUTOLOAD:'.$class); };
spl_autoload_register($autoload,true,true);
try {
    cardReadCheck(PHP_INT_SIZE===8,'64-bit cents boundary');
    foreach (['1.01','100.01','0.01'] as $amount) {
        $expected=$amount==='1.01'?['1.01','0.68','0.35','0.00']:
            ($amount==='100.01'?['100.01','66.68','33.35','0.00']:['0.01','0.01','0.01','0.00']);
        foreach ($expected as $consumed=>$value) {
            cardReadValue([['id'=>19,'pay_price'=>$amount],[],[],null,3,3-$consumed],$value,'legacy three uses '.$amount.' index '.$consumed);
            cardReadValue([['id'=>19,'pay_price'=>'999'],['rh_source'=>['source_line_paid_amount'=>$amount]],[],null,3,3-$consumed],
                $value,'import source overrides cart '.$amount.' index '.$consumed);
        }
        $parts=[];for ($i=0;$i<3;$i++) $parts[]=bcsub($expected[$i],$expected[$i+1],2);
        cardReadCheck(bcadd(bcadd($parts[0],$parts[1],2),$parts[2],2)===$amount,'three-use cents conservation '.$amount);
        cardReadCheck($parts[0]===$parts[1] && bccomp($parts[2],$parts[0],2)>=0,'last use owns remainder '.$amount);
    }
    foreach ([['100.01',3333,1,1,'33.35'],['100.01',3334,1,1,'33.34'],['100.01',3335,1,0,'0.00'],
        ['100.01',10001,3,0,'0.00'],['1.01',1,1,2,'1.00'],['1.01',0,0,0,'0.00']] as $case) {
        [$amount,$refund,$removed,$remaining,$expected]=$case;
        cardReadValue([['id'=>19,'pay_price'=>$amount],['r26RefundedCents'=>$refund,'r26RefundRemovedTimes'=>$removed],[],null,3,$remaining],
            $expected,'refund/service/removal separation '.json_encode($case));
    }
    foreach (['0','0.00',0] as $zero) cardReadValue([['id'=>19,'pay_price'=>$zero],[],[],null,3,2],'0.00','zero is legitimate');
    cardReadValue([['id'=>19],[],[],null,3,2],'0.00','existing absent cart default');
    cardReadValue([['id'=>19,'pay_price'=>' 1.01 '],[],[],null,3,2],'0.68','existing surrounding trim');
    cardReadValue([['id'=>19,'pay_price'=>'malformed'],['rh_source'=>['source_line_paid_amount'=>'1.01']],[],null,3,2],
        '0.68','authoritative source ignores unrelated malformed cart price');
    foreach (['-1','-0.01','-0','+1.01','01.01','.01','1.','1.001','1e2','1E+2','1.01e0','1,01','1 01','abc','',
        null,true,false,1.01,1.0,INF,NAN,[],new stdClass(),str_repeat('9',120).'.01','92233720368547758.08','92233720368547759.00'] as $raw) {
        // Existing null/missing cart price defaults to zero; an explicitly
        // recorded imported null is invalid and must not fall back to the cart.
        if ($raw===null) cardReadValue([['id'=>19,'pay_price'=>null],[],[],null,3,2],'0.00','existing null cart default');
        else cardReadReject([['id'=>19,'pay_price'=>$raw],[],[],null,3,2],'invalid/overflow cart amount '.gettype($raw));
        cardReadReject([['id'=>19,'pay_price'=>'100.00'],['rh_source'=>['source_line_paid_amount'=>$raw]],[],null,3,2],
            'invalid/overflow recorded source never silently falls back');
    }
    $maximum=intdiv(PHP_INT_MAX,100).'.'.str_pad((string)(PHP_INT_MAX%100),2,'0',STR_PAD_LEFT);
    cardReadValue([['id'=>19,'pay_price'=>$maximum],[],[],null,3,3],$maximum,'maximum integer cents exact');
    cardReadValue([['id'=>19,'pay_price'=>$maximum],[],[],null,3,1],'30744573456182586.03','maximum three-use tail exact');
    foreach ([[0,0],[-1,0],[3,-1],[3,4],[PHP_INT_MIN,PHP_INT_MAX]] as [$total,$remaining])
        cardReadReject([['id'=>19,'pay_price'=>'1.01'],[],[],null,$total,$remaining],'invalid quota rejected');
    foreach ([['r26RefundedCents'=>-1],['r26RefundedCents'=>'1'],['r26RefundedCents'=>1.0],['r26RefundedCents'=>69],
        ['r26RefundRemovedTimes'=>-1],['r26RefundRemovedTimes'=>2],['r26RefundRemovedTimes'=>'1'],['r26RefundRemovedTimes'=>1.0]] as $refund)
        cardReadReject([['id'=>19,'pay_price'=>'1.01'],$refund,[],null,3,2],'invalid or excessive refund/removal');

    // Whole-yuan paths retain whole-yuan per-use rounding, not the cent split.
    foreach (['10','10.00',10,'0'] as $whole) foreach ([3,2,1,0] as $remaining)
        cardReadDelegated([['id'=>19,'pay_price'=>$whole],[],[],null,3,$remaining],'whole path unchanged');
    cardReadValue([['id'=>19,'pay_price'=>'10.00'],[],[],null,3,2],'7.00','whole path is not cent-rounded 6.67');
    $bad=['rh_source'=>['source_line_paid_amount'=>'not money']];
    cardReadDelegated([['id'=>19,'pay_price'=>'not money'],$bad,[19=>10000],['purchaseAmountCents'=>90000],3,2],'V3 overrides rule and malformed legacy');
    cardReadDelegated([['id'=>19,'pay_price'=>'1.01'],[],[19=>0],['purchaseAmountCents'=>90000],3,2],'zero V3 is authoritative');
    cardReadDelegated([['id'=>19,'pay_price'=>'1.01'],[],[19=>10001],['purchaseAmountCents'=>90000],3,2],'fractional V3 preserves dependency-specific behavior');
    if ($mode==='current') cardReadReject([['id'=>19,'pay_price'=>'1.01'],[],[19=>10001],null,3,2],
        'strict current authority rejects fractional formal V3 pool');
    else cardReadValue([['id'=>19,'pay_price'=>'1.01'],[],[19=>10001],null,3,2],'66.68',
        'frozen formal V3 retains its own established cent behavior');
    cardReadDelegated([['id'=>19,'pay_price'=>'1.01'],[],[19=>-1],null,3,2],'negative V3 still rejected');
    cardReadDelegated([['id'=>19,'pay_price'=>'1.01'],$bad,[],['purchaseAmountCents'=>10000],3,2],'formal rule overrides invalid legacy');
    cardReadDelegated([['id'=>19,'pay_price'=>'1.01'],['r26RefundedCents'=>3333,'r26RefundRemovedTimes'=>1],[],
        ['purchaseAmountCents'=>10000,'r26RefundedCents'=>999999],3,1],'current refunds override stale rule refund state');
    cardReadDelegated([['id'=>19,'pay_price'=>'1.01'],['rh_source'=>['source_line_paid_amount'=>'1.01']],[],
        ['purchaseAmountCents'=>101],3,2],'invalid formal rule does not widen legacy escape');
    cardReadValue([['id'=>19,'pay_price'=>'1.01'],[],[20=>10000],null,3,2],'0.68','other component allocation cannot replace this component');
    foreach (['cashier_v3_project_replacement','cashier_v3_project_upgrade'] as $sourceType) {
        $snapshot=['sourceType'=>$sourceType,'amountCalculationVersion'=>Allocator::CALCULATION_VERSION];
        cardReadValue([['id'=>19,'pay_price'=>'1.01'],$snapshot,[],['purchaseAmountCents'=>90000],3,2],
            '0.68','independent legacy fraction ignores shared rule');
        cardReadDelegated([['id'=>19,'pay_price'=>'10.00'],$snapshot,[],['purchaseAmountCents'=>90000],3,2],
            'independent whole snapshot keeps original path');
    }
    foreach (['1.01','0.01','100.01','-1','1e2'] as $amount) {
        $denied=false;try { Allocator::allocate($amount,3,0,1); } catch (InvalidArgumentException $error) { $denied=true; }
        cardReadCheck($denied,'write allocator still rejects fractional/malformed input');
    }
    cardReadCheck(Allocator::allocate('10.00',3,0,1)==='3.00' && Allocator::allocate('10.00',3,2,1)==='4.00','write whole-yuan final-use tail unchanged');
    cardReadCheck(hash_file('sha256',$allocatorFile)==='2fd37698ff7a227adc67c504e15cfd3d44b144b867124e9d987b449750f4f722',
        'allocator dependency is byte-identical to frozen e0 in both runs');

    // Runtime never boots an application; source guards additionally forbid DB,
    // IO, writer and newly-invented marker references in this narrow pure helper.
    $helperSource=file_get_contents($helperFile);$tokens=token_get_all($helperSource);$executable='';
    foreach ($tokens as $token) if (!is_array($token) || !in_array($token[0],[T_COMMENT,T_DOC_COMMENT],true)) $executable.=is_array($token)?$token[1]:$token;
    foreach (['Db','PDO','mysqli','query','execute','exec','insert','update','delete','save','file_put_contents','fopen','beginTransaction','commit','rollBack'] as $forbidden)
        cardReadCheck(!preg_match('/\b'.preg_quote($forbidden,'/').'\b/i',$executable),'pure helper has no '.$forbidden.' reference');
    cardReadCheck(strpos($executable,'operation-cent-')===false && strpos($executable,'amountCalculationVersion')===false,'helper invents no amount/version marker');
    $references=[];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($backend.'/app',FilesystemIterator::SKIP_DOTS)) as $file)
        if ($file->isFile() && $file->getExtension()==='php' && strpos(file_get_contents($file->getPathname()),'CashierV3CardReadAmountServices')!==false)
            $references[]=substr($file->getPathname(),strlen($backend)+1);
    sort($references);
    cardReadCheck($references===['app/services/cashier/v3/card/CashierV3CardReadAmountServices.php',
        'app/services/cashier/v3/member/CashierV3MemberDetailQueryServices.php'],'only helper definition and member reader refer to helper');
    $member=file_get_contents($memberFile);
    cardReadCheck((bool)preg_match('/calcEffectiveWriteSurplusTimes\([\s\S]*?false\s*\)/',$member)
        && (bool)preg_match('/usableEntitlementAmount\([\s\S]*?false\s*\)/',$member),'debt and shared-quota read calls retain no-lock flag');
    cardReadCheck($beforeHashes===array_map('hash_file',array_fill(0,count($observedFiles),'sha256'),$observedFiles),
        'all observed source fingerprints stable throughout run');
} finally { spl_autoload_unregister($autoload); }
echo json_encode(['result'=>'PASS','checks'=>$checks,'authority_mode'=>$mode,
    'authority_sha256'=>hash('sha256',$authoritySource),'allocator_sha256'=>hash_file('sha256',$allocatorFile),
    'helper_sha256'=>hash_file('sha256',$helperFile),'php'=>PHP_VERSION,
    'framework_booted'=>false,'database_connections'=>0,'business_writes'=>false],JSON_THROW_ON_ERROR),PHP_EOL;
