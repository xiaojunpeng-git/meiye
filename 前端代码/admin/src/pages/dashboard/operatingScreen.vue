<template>
  <div class="operating-screen">
    <header class="screen-header">
      <div class="screen-title"><span class="mark">▮▮▮</span><div><small>瑞昊集团</small><h1>总部经营数据大屏</h1></div></div>
      <strong>瑞昊集团</strong>
      <div class="screen-meta"><span>● 全部门店 · 132 家</span><span>2026年07月19日&nbsp;10:32</span><span>5 分钟自动刷新</span><button @click="toggleFullscreen">⛶</button></div>
    </header>
    <section class="screen-summary">
      <div><small>TODAY'S PERFORMANCE</small><h2>经营总览 · {{ period }}</h2></div>
      <div class="screen-controls"><div class="tabs"><button v-for="item in periods" :key="item" :class="{active: item === period}" @click="period = item">{{ item }}</button></div><p>数据更新时间：10:30:12　·　统计口径：经营指标按业务发生日，退款按退款日期</p></div>
    </section>
    <section class="metrics"><article v-for="item in metrics" :key="item.name" :class="['metric', item.tone]" :title="item.note"><div><span>{{ item.name }}</span><em>{{ compareLabel[0] }} <b>{{ item.yoy }}</b>　{{ compareLabel[1] }} <b>{{ item.mom }}</b></em></div><strong>{{ item.value }}</strong><p>{{ item.sub }}</p></article></section>
    <section class="screen-content">
      <article class="panel trend"><h3>近 7 日现金与消耗趋势</h3><div class="chart"><i v-for="(bar, index) in trend" :key="index" :style="{height: bar + '%'}"></i></div><p>07/13　　07/14　　07/15　　07/16　　07/17　　07/18　　今日</p></article>
      <article class="panel customers"><h3>客户经营</h3><div class="customer-grid"><div v-for="item in customers" :key="item.name" :title="item.note"><span>{{ item.name }}</span><b>{{ item.value }}</b><small>{{ item.sub }}</small></div></div></article>
      <article class="panel ranking"><h3>门店现金业绩排名</h3><div class="rank-cols"><ol><li v-for="item in topStores" :key="item.name"><b>{{ item.name }}</b><i></i><span>{{ item.value }}</span></li></ol><ol class="bottom"><li v-for="item in bottomStores" :key="item.name"><b>{{ item.name }}</b><i></i><span>{{ item.value }}</span></li></ol></div></article>
      <aside class="panel developing"><div><h3>客情风险管控</h3><span>待开发 · 敬请期待</span><p>投诉总量　--　　未完结投诉　--　　逾期未处理　--</p></div><div><h3>门店成本管控</h3><span>开发中 · 敬请期待</span><p>门店整体支出　--　　院装耗材成本　--　　异常支出预警　--</p></div></aside>
    </section>
  </div>
</template>

<script>
export default {
  name: 'OperatingScreen',
  data () {
    return {
      period: '今日',
      periods: ['今日', '本月', '本年度', '自定义'],
      trend: [34, 52, 46, 67, 55, 82, 72],
      metrics: [
        { name: '现金业绩', value: '¥286,420', yoy: '+12.6%', mom: '+8.2%', sub: '目标达成 76.4%　目标 ¥375,000', tone: 'primary', note: '销售报表口径的有效现金业绩，按业务发生日统计。' },
        { name: '实际业绩', value: '¥218,640', yoy: '+9.4%', mom: '+6.7%', sub: '占现金业绩 76.3%　分成 ¥67,780', note: '现金业绩扣除分成后的实际业绩。' },
        { name: '消耗业绩', value: '¥164,380', yoy: '-3.1%', mom: '+2.4%', sub: '服务客次 426　客单消耗 ¥386', tone: 'warm', note: '客户核销项目或卡项产生的消耗业绩。' },
        { name: '退款总额', value: '¥8,260', yoy: '-0.6%', mom: '+0.4%', sub: '退款率 2.88%', tone: 'risk', note: '退款成功金额，按退款日期统计。' }
      ],
      customers: [{ name: '新客数', value: 86, sub: '会员且现金业绩 ≥ ¥298', note: '销售报表口径的新客数。' }, { name: '散客数', value: 41, sub: '游客或现金业绩 < ¥298', note: '销售报表口径的散客数。' }, { name: '到店客户', value: 318, sub: '核销客户去重', note: '发生核销的去重客户人数。' }, { name: '复购客户', value: 124, sub: '历史成交后再次成交', note: '历史有成交且本期再次成交的客户。' }],
      topStores: [{ name: '杭州西湖店', value: '¥38,620' }, { name: '上海静安店', value: '¥35,980' }, { name: '南京新街口店', value: '¥32,760' }, { name: '苏州园区店', value: '¥29,480' }, { name: '合肥政务店', value: '¥27,360' }],
      bottomStores: [{ name: '金华江北店', value: '¥4,260' }, { name: '常州钟楼店', value: '¥3,980' }, { name: '宁波北仑店', value: '¥3,420' }, { name: '嘉兴南湖店', value: '¥2,860' }, { name: '绍兴越城店', value: '¥2,240' }]
    }
  },
  computed: { compareLabel () { return this.period === '今日' ? ['日同比', '日环比'] : this.period === '本月' ? ['月同比', '月环比'] : this.period === '本年度' ? ['年同比', '年环比'] : ['同期同比', '等长环比'] } },
  methods: {
    toggleFullscreen () {
      const node = document.documentElement
      document.fullscreenElement ? document.exitFullscreen() : node.requestFullscreen()
    },
    exitFullscreenByEsc (event) {
      if (event.key === 'Escape' && document.fullscreenElement) document.exitFullscreen()
    }
  },
  mounted () { document.addEventListener('keydown', this.exitFullscreenByEsc) },
  beforeDestroy () { document.removeEventListener('keydown', this.exitFullscreenByEsc) }
}
</script>

<style lang="less" scoped>
.operating-screen{min-height:100vh;padding:12px 28px;background:#07111f;color:#dcebf5;font-family:PingFang SC,Microsoft YaHei,sans-serif;box-sizing:border-box}.screen-header{height:72px;display:flex;align-items:flex-end;justify-content:space-between;padding-bottom:8px;border-bottom:1px solid rgba(130,182,214,.18)}.screen-title{display:flex;gap:10px;align-items:center}.mark{color:#28d4bd;letter-spacing:-5px;font-size:30px}.screen-title small{font-size:10px;color:#7d9cb0;letter-spacing:3px}.screen-title h1{margin:2px 0 0;font-size:22px}.screen-header>strong{font-size:36px;letter-spacing:12px}.screen-meta{display:flex;gap:16px;align-items:center;color:#8da6b9;font-size:12px}.screen-meta span:first-child{color:#c5dceb}.screen-meta button{border:0;background:none;color:#dcebf5;font-size:24px;cursor:pointer}.screen-summary{display:flex;justify-content:space-between;align-items:flex-end;padding:8px 0 10px}.screen-summary small,.panel h3{color:#6090ad;font-size:10px;letter-spacing:2px}.screen-summary h2{margin:4px 0 0;font-size:21px}.screen-controls{display:flex;flex-direction:column;align-items:flex-end;gap:5px}.tabs{padding:3px;border:1px solid rgba(130,182,214,.18);border-radius:6px}.tabs button{border:0;background:none;color:#9bb3c4;padding:6px 14px;cursor:pointer}.tabs .active{background:#1aaa9e;color:white;border-radius:4px}.screen-controls p{margin:0;color:#708da2;font-size:11px}.metrics{display:grid;grid-template-columns:1.2fr repeat(3,1fr);gap:12px}.metric,.panel{border:1px solid rgba(130,182,214,.17);border-radius:8px;background:#0e1c2c}.metric{padding:16px}.metric>div{display:flex;justify-content:space-between;font-size:12px;color:#b8ccda}.metric em{font-style:normal;font-size:10px;color:#7892a5}.metric em b{color:#27d4bc}.metric strong{display:block;margin:13px 0;font-size:30px}.metric p{margin:0;color:#7892a5;font-size:11px}.primary{background:linear-gradient(110deg,#0e575a,#0e1c2c)}.warm em b{color:#f3ad55}.risk em b{color:#fc6c80}.screen-content{height:calc(100vh - 274px);min-height:520px;display:grid;grid-template-columns:2.05fr .87fr;grid-template-rows:1fr 1.14fr;gap:12px;margin-top:12px}.panel{padding:16px}.panel h3{margin:0 0 14px;color:#dcebf5;font-size:16px;letter-spacing:0}.trend{position:relative}.chart{height:calc(100% - 54px);display:flex;align-items:flex-end;gap:10%;padding:0 7%;border-bottom:1px solid rgba(130,182,214,.16)}.chart i{width:6%;background:linear-gradient(#29d6bd,rgba(41,214,189,.15));border-radius:4px 4px 0 0}.trend p{margin:8px 5%;color:#668399;font-size:10px;word-spacing:20px}.customer-grid{display:grid;grid-template-columns:1fr 1fr;gap:18px}.customer-grid div{border-left:2px solid #2589a5;padding-left:9px}.customer-grid span,.customer-grid small{display:block;color:#7e9aae;font-size:11px}.customer-grid b{display:block;margin:4px 0;font-size:25px}.ranking{grid-row:2}.rank-cols{display:grid;grid-template-columns:1fr 1fr;gap:28px}.rank-cols ol{margin:0;padding:0;list-style:none}.rank-cols li{display:grid;grid-template-columns:110px 1fr 70px;gap:8px;align-items:center;height:40px;border-bottom:1px solid rgba(130,182,214,.09);font-size:11px}.rank-cols i{height:4px;background:#1dbda9;border-radius:4px}.rank-cols .bottom i{background:#c84d68}.rank-cols span{text-align:right}.developing{display:grid;grid-template-rows:1fr 1fr;gap:12px}.developing>div{position:relative;border-bottom:1px solid rgba(130,182,214,.12)}.developing span{position:absolute;right:0;top:0;color:#7e99ad;font-size:10px}.developing p{color:#7591a5;font-size:11px;margin-top:24px}@media(max-width:1100px){.screen-header>strong{display:none}.screen-content{grid-template-columns:1.6fr 1fr}.screen-meta{gap:7px}.screen-meta span:nth-child(2){display:none}}
</style>
