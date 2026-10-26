<template>
<!-- 运营-首页-顶部组件 -->
    <Row :gutter="24" class="pl-6 pr-6">
        <Col v-bind="grid" class="ivu-mb" v-for="(item, index) in infoList" :key="index">
            <Card :bordered="false" dis-hover :padding="16">
                <p class="title">
                    <span v-text="item.title"></span>
                </p>
                <Tag slot="extra" color="blue">{{item.date}}</Tag>
                <div class="mt7 text-333">
                    <Numeral :value="item.today" v-font="34" class="SemiBold" />
                    <div v-height="33" class="text-606266">
						<span v-display="'inline-block'">
						    <!-- 周同比 <Trend :flag="Number(item.week_ratio)>=0?'up':'down'">{{Number(item.week_ratio)}}%</Trend> -->
						    昨日 {{Number(item.yesterday)}} <span class="nl"></span>
						</span>
                        <span v-display="'inline-block'" class="ivu-mr ml2">
                            <!-- 日同比 <Trend :flag="Number(item.today_ratio)>=0?'up':'down'">{{Number(item.today_ratio)}}%</Trend> -->
                            日环比 <span class="iconColor" :class="Number(item.today_ratio)>=0?'on':''"><Icon :type="Number(item.today_ratio)>=0?'md-arrow-dropup':'md-arrow-dropdown'"/>{{Number(item.today_ratio)}}%</span>
                        </span>
                        
                    </div>
                    <Divider style="margin: 8px 0" />
                    <div>
                        <Row>
                            <Col span="12" v-text="item.total_name"></Col>
                            <Col span="12" class="ivu-text-right">{{item.total}}</Col>
                        </Row>
                    </div>
                </div>
            </Card>
        </Col>
    </Row>
</template>
<script>
    import echarts from 'echarts';
    import { headerApi } from '@/api/index';
    export default {
        data () {
            return {
                infoList: [],
                grid: {
                    xl: 6,
                    lg: 12,
                    md: 12,
                    sm: 12,
                    xs: 24
                },
                excessStyle: {
                    color: '#f56a00',
                    backgroundColor: '#fde3cf'
                },
                avatarList: []
            }
        },
        methods: {
            // 统计
            getStatistics () {
                headerApi().then(async res => {
                    let data = res.data
                    this.infoList = data.info;
                }).catch(res => {
                    this.$Message.error(res.msg);
                })
            }
        },
        mounted () {
            this.getStatistics();
        }
    }
</script>
<style lang="stylus" scoped>
	/deep/.ivu-card-head{
		border-bottom:0;
	}
	/deep/.ivu-icon{
		font-size: 16px;
	}
	/deep/.ivu-row{
		padding: 2px 0;
		color: #606266;
	}
	/deep/.ivu-card-extra{
		top:18px;
	}
	.ivu-mb{
		padding: 0 6px !important;
		margin-bottom: 12px !important;
	}
	.title{
		font-weight: 600 !important;
		font-size: 16px;
		margin-top: 4px;
		color: #333;
	}
	.nl{
		display inline-block
		padding 0 3px
	}
	.number{
		font-size 30px;
		margin-bottom: 10px;
	}
	.iconColor{
		color: #F56464;
		font-size: 14px;
	}
	.iconColor.on{
		color: #19BE6B;
	}
	.ivu-mr{
		margin-right: 16px !important;
	}
	.ivu-text-right {
		text-align: right;
	}
</style>