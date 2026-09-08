<?php
namespace app\services\query\metric;

/** Display only. Authoritative values and exports retain their original signed cents. */
final class MetricMoneyFormatter
{
    public static function integerYuan($cents): string
    {
        if (!is_int($cents)) throw new \RuntimeException('METRIC_AMOUNT_INVALID');
        // Decimal text avoids abs(PHP_INT_MIN), overflowing +50, and float rounding.
        $negative=$cents<0; $digits=ltrim((string)$cents,'-');
        $digits=str_pad($digits,3,'0',STR_PAD_LEFT);
        $whole=substr($digits,0,-2); $fraction=(int)substr($digits,-2);
        if ($fraction>=50) {
            $carry=1;
            for ($i=strlen($whole)-1;$i>=0 && $carry;$i--) {
                $digit=(int)$whole[$i]+1; $whole[$i]=(string)($digit%10); $carry=$digit===10?1:0;
            }
            if ($carry) $whole='1'.$whole;
        }
        $whole=ltrim($whole,'0'); if ($whole==='') return '0';
        return ($negative?'-':'').$whole;
    }
}
