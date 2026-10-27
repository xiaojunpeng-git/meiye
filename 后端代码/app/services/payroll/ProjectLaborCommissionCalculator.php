<?php
declare(strict_types=1);
namespace app\services\payroll;

/**
 * 项目提成纯计算器：输入是已授权劳动事实、整月落档基数及精确项目配置。
 * 返回只读预计金额，不写回劳动业绩、手工费或历史工资；金额全程十进制定点。
 */
final class ProjectLaborCommissionCalculator
{
    /** 区间首档含零；连续档以先前上限衔接，重复边界由较高档取得。 */
    public function rate(array $configuration, int $monthlySalesCents): array
    {
        $tiers = [];
        foreach ($configuration as $range=>$rate) {
            if (!preg_match('/^(\d+)-(\d+)$/D', (string)$range, $match)
                || !is_scalar($rate) || !preg_match('/^\d+(?:\.\d{1,4})?$/D', (string)$rate)
                || bccomp((string)$rate, '100', 4) > 0 || (int)$match[2] < (int)$match[1]) {
                throw new \DomainException('项目提成档位或比例无效');
            }
            $tiers[] = ['min'=>(int)$match[1]*100, 'max'=>(int)$match[2]*100,
                'rate'=>(string)$rate, 'range'=>(string)$range];
        }
        usort($tiers, static function ($a,$b) { return $a['min'] <=> $b['min']; });
        if (!$tiers || $tiers[0]['min'] !== 0) throw new \DomainException('项目提成缺少起始档');
        for ($i=1;$i<count($tiers);$i++) {
            $previous=$tiers[$i-1]; $current=$tiers[$i];
            if ($current['min'] <= $previous['min'] || $current['min'] < $previous['max']
                || $current['min'] > $previous['max']+100) throw new \DomainException('项目提成档位重叠或不连续');
        }
        $sales=max(0,$monthlySalesCents);
        foreach ($tiers as $i=>$tier) {
            $next=$tiers[$i+1] ?? null;
            // 历史小数月基数在两档整数标注之间仍有唯一归属，不能产生一元宽的空档。
            if ($next && ($next['min'] === $tier['max'] ? $sales >= $next['min'] : $sales > $tier['max'])) continue;
            if (!$next && $sales > $tier['max']) throw new \DomainException('销售业绩超出项目已配置档位');
            return $tier;
        }
        throw new \DomainException('销售业绩未命中唯一档位');
    }

    /** 资格由原核销保存的劳动分配决定；零劳动不因开关产生提成，不回读当前类型改写历史。 */
    public function calculate(array $fact, array $configuration, int $monthlySalesCents): array
    {
        $base=(int)$fact['metric_value'];
        if ($base === 0) {
            return ['amount_cents'=>0,'basis_cents'=>0,'threshold_cents'=>$monthlySalesCents,'rate_percent'=>'0','range'=>'不适用'];
        }
        $tier=$this->rate($configuration,$monthlySalesCents);
        $raw=bcdiv(bcmul((string)$base,$tier['rate'],8),'100',8);
        $absolute=ltrim($raw,'-');
        $rounded=(int)bcadd($absolute,'0.5',0);
        return ['amount_cents'=>$base<0 ? -$rounded : $rounded,'basis_cents'=>$base,
            'threshold_cents'=>$monthlySalesCents,'rate_percent'=>$tier['rate'],'range'=>$tier['range']];
    }
}
