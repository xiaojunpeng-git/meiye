<?php
namespace app\services\query\metric;

/** 权威指标始终按有符号分存储；页面转整数元，导出可转保留分精度的元。 */
final class MetricMoneyFormatter
{
    /** 导出保留分精度并以元表示，避免把保存的整数分值误当成元或经浮点换算。 */
    public static function exactYuan(int $cents): string
    {
        $negative = $cents < 0;
        $digits = str_pad(ltrim((string)$cents, '-'), 3, '0', STR_PAD_LEFT);
        $whole = ltrim(substr($digits, 0, -2), '0');
        if ($whole === '') $whole = '0';
        $fraction = rtrim(substr($digits, -2), '0');
        $value = $whole . ($fraction === '' ? '' : '.' . $fraction);
        return $negative && $value !== '0' ? '-' . $value : $value;
    }

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
