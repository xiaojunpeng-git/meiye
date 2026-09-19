<template>
    <div class="mobile-page":style="{
		background:bottomBgColor,
		marginTop:mTop+'px',
		paddingTop:topConfig+'px',
		paddingBottom:bottomConfig+'px',
		paddingLeft:prConfig+'px',
		paddingRight:prConfig+'px'
		}">
		<div class="pictrue">
			<div class="entry-grid" v-if="isEntryGrid">
				<div v-for="(item,index) in entryList" :key="item.number || index" class="entry-item">
					<span>0{{index + 1}}</span>{{item.name || '快捷入口'}}
				</div>
			</div>
			<img :src="imgUrl" v-else-if="imgUrl" :style="{
				borderRadius:bgRadius
			}"/>
			<div class="empty-box" v-else :style="{
				borderRadius:bgRadius
			}">
			    <img src="../../assets/images/shan.png"/>
			</div>
		</div>
    </div>
</template>

<script>
import { mapState, mapMutations } from 'vuex';
export default {
  name: 'home_hotspot',
  cname: '热区',
  configName: 'c_hotspot',
  icon: '#iconzujian-requ',
  type: 0, // 0 基础组件 1 营销组件 2工具组件
  defaultName: 'hotspot', // 外面匹配名称
  props: {
    index: {
      type: null,
      default: -1
    },
    num: {
      type: null
    }
  },
  computed: {
    ...mapState('admin/mobildConfig', ['defaultArray'])
  },
  watch: {
    pageData: {
      handler(nVal, oVal) {
        this.setConfig(nVal);
      },
      deep: true
    },
    num: {
      handler(nVal, oVal) {
        const data = this.$store.state.admin.mobildConfig.defaultArray[nVal];
        this.setConfig(data);
      },
      deep: true
    },
    'defaultArray': {
      handler(nVal, oVal) {
        const data = this.$store.state.admin.mobildConfig.defaultArray[this.num];
        this.setConfig(data);
      },
      deep: true
    }
  },
  data() {
    return {
      // 默认初始化数据禁止修改
      defaultConfig: {
        cname: '热区',
        name: 'hotspot',
        timestamp: this.num,
        isHide: false,
        setUp: {
					  tabVal: 0
        },
        titleLeft: '内容设置',
        titleRight: '通用样式',
        picStyle: {
          url: '',
          list: []
        },
		layoutConfig: {
			title: '展示方式',
			tabVal: 0,
			tabList: [
				{ name: '热区图' },
				{ name: '快捷入口' }
			]
		},
        bottomBgColor: {
					    title: '底部背景',
					    name: 'bottomBgColor',
					    default: [{
					        item: '#F5F5F5'
					    }],
					    color: [
					        {
					            item: '#F5F5F5'
					        }
					    ]
        },
        topConfig: {
					    title: '上边距',
					    val: 0,
					    min: 0
        },
        bottomConfig: {
					    title: '下边距',
					    val: 0,
					    min: 0
        },
        prConfig: {
					    title: '左右边距',
					    val: 0,
					    min: 0
        },
        mbConfig: {
					    title: '页面上间距',
					    val: 0,
					    min: 0
        },
        fillet: {
          title: '背景圆角',
          type: 0,
          list: [
						  {
						    val: '全部',
						    icon: 'iconcaozuo-zhengti'
						  },
						  {
						    val: '单个',
						    icon: 'iconcaozuo-bianjiao'
						  }
          ],
          valName: '圆角值',
          val: 0,
          min: 0,
          valList: [
            { val: 0 },
            { val: 0 },
            { val: 0 },
            { val: 0 }
          ]
        }
      },
      bottomBgColor: '',
      confObj: {},
      pageData: {},
      topConfig: '',
      bottomConfig: '',
      prConfig: 0,
      bgRadius: 0,
      imgUrl: '',
		mTop: 0,
		isEntryGrid: false,
		entryList: []
    };
  },
  mounted() {
    this.$nextTick(() => {
      this.pageData = this.$store.state.admin.mobildConfig.defaultArray[this.num];
      this.setConfig(this.pageData);
    });
  },
  methods: {
    setConfig(data) {
      if (!data) return;
      if (data.mbConfig) {
        this.bottomBgColor = data.bottomBgColor.color[0].item;
        this.topConfig = data.topConfig.val;
        this.bottomConfig = data.bottomConfig.val;
        this.prConfig = data.prConfig.val;
        this.mTop = data.mbConfig.val;
        this.imgUrl = data.picStyle.url;
		this.isEntryGrid = Number(data.layoutConfig && data.layoutConfig.tabVal) === 1;
		this.entryList = data.picStyle.list || [];
        const fillet = data.fillet.type;
        const filletVal = data.fillet.val;
        const valList = data.fillet.valList;
        this.bgRadius = fillet ? valList[0].val + 'px ' + valList[1].val + 'px ' + valList[3].val + 'px ' + valList[2].val + 'px' : filletVal + 'px';
      }
    }
  }
};
</script>

<style scoped lang="stylus">
	.pictrue
		width 100%
		height 100%
		.empty-box
			width 100%
			height 379px
			border-radius 0
			background #F3F9FF
			img
				width 65px
				height 50px
		img
			width 100%
			height 100%
		.entry-grid
			display grid
			grid-template-columns repeat(2, minmax(0, 1fr))
			gap 8px
			padding 10px
			border-radius 10px
			background linear-gradient(135deg, #fffdfb, #f6e9e6)
			.entry-item
				display flex
				align-items center
				min-height 42px
				padding 0 12px
				border-radius 8px
				background rgba(255,255,255,.82)
				color #5f3040
				font-size 12px
				font-weight 600
				span
					margin-right 8px
					color #bd8793
					font-style italic
				&:last-child:nth-child(odd)
					grid-column 1 / -1
</style>
