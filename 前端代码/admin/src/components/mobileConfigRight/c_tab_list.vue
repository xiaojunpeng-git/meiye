<template>
    <div class="c_product" v-if="configData">
        <div class="title">{{configData.title}}</div>
        <div class="list-box">
            <draggable
                    class="dragArea list-group"
                    :list="configData.list"
                    group="peoples"
                    handle=".move-icon"
            >
                <div class="item" v-for="(item,index) in configData.list" :key="index">
                    <div class="move-icon">
                        <span class="iconfont-diy iconxingzhuangjiehe"></span>
                    </div>
					<div>
						<div class="con-item">
						    <span>{{item.text.title}}</span>
						    <div>
						        <Input v-model="item.text.val" :placeholder="item.text.pla" :maxlength="item.text.max" show-word-limit/>
						    </div>
						</div>
						<div class="con-item">
							<span>{{item.dataType.title}}</span>
							<div>
								<RadioGroup v-model="item.dataType.tabVal">
								    <Radio :label="key" v-for="(radio,key) in item.dataType.tabList" :key="key">
								        <span>{{radio.name}}</span>
								    </Radio>
								</RadioGroup>
							</div>
						</div>
						<div class="con-item">
							<span>{{item.dataType.tabList[item.dataType.tabVal].name}}</span>
							<div @click="getLink(index)">
							    <Input icon='ios-arrow-forward' v-model="item.dataType.tabVal?item.classPage.name:item.microPage.name" :placeholder="item.dataType.tabVal?'选择分类':'选择页面'" readonly/>
							</div>
						</div>
					</div>
                    <div class="delete" @click.stop="bindDelete(index)">
                        <Icon type="ios-close-circle" size="24"/>
                    </div>
                </div>
            </draggable>
        </div>
        <div v-if="configData.list">
            <div class="add-btn" @click="addHotTxt" v-if="configData.list.length < configData.max">
                <Button class="btn" type="primary" ghost>
					<span class="iconfont iconjiahao"></span>添加
				</Button>
            </div>
        </div>
        <linkaddress ref="linkaddres" :linkType='1' :fromType='"diyPage"' :isCateTree="!['homeComb', 'tabNav'].includes(defaults.name)" @linkUrl="linkUrl"></linkaddress>
    </div>
</template>

<script>
import vuedraggable from 'vuedraggable';
import linkaddress from '@/components/linkaddress';

export default {
  name: 'c_tab_list',
  props: {
    configObj: {
      type: Object
    },
    configNme: {
      type: String
    },
    index: {
      type: null
    }
  },
  components: {
    linkaddress,
    draggable: vuedraggable
  },
  data() {
    return {
      defaults: {},
      configData: {},
      itemObj: {},
      activeIndex: 0
    };
  },
  mounted() {
    this.$nextTick(() => {
      this.defaults = this.configObj;
      this.configData = this.configObj[this.configNme];
    });
  },
  watch: {
    configObj: {
      handler(nVal, oVal) {
        this.defaults = nVal;
        this.configData = nVal[this.configNme];
      },
      deep: true
    }
  },
  methods: {
    linkUrl(e) {
      if (this.configData.list[this.activeIndex].dataType.tabVal) {
        const obj = e.split('?')[1];
        const obj2 = obj.split('&');
        this.configData.list[this.activeIndex].classPage.name = obj2[1].split('=')[1];
        this.configData.list[this.activeIndex].classPage.id = obj2[0].split('=')[1];
      } else {
        const obj = e.split('?')[1];
        const obj2 = obj.split('&');
        this.configData.list[this.activeIndex].microPage.name = obj2[1].split('=')[1];
        this.configData.list[this.activeIndex].microPage.id = obj2[0].split('=')[1];
      }
    },
    getLink(index) {
      this.activeIndex = index;
      let obj = {};
      if (this.configData.list[this.activeIndex].dataType.tabVal) {
        obj = {
          id: 8,
          pid: 2,
          type: 'product_category'
        };
      } else {
        obj = {
          id: 7,
          pid: 1,
          type: 'special'
        };
      }
      this.$refs.linkaddres.handleCheckChange('', obj);
      this.$refs.linkaddres.modals = true;
    },
    addHotTxt() {
      if (this.configData.list.length == 0) {
        const storage = window.localStorage;
        this.itemObj = JSON.parse(storage.getItem('itemObj'));
        this.itemObj.dataType.tabVal = 0;
        this.itemObj.microPage.name = '';
        this.itemObj.classPage.name = '';
        this.configData.list.push(this.itemObj);
      } else {
        const obj = JSON.parse(JSON.stringify(this.configData.list[this.configData.list.length - 1]));
        obj.dataType.tabVal = 0;
        obj.microPage.name = '';
        obj.classPage.name = '';
        this.configData.list.push(obj);
      }
    },
    // 删除数组
    bindDelete(index) {
      if (this.configData.list.length == 1) {
        const itemObj = this.configData.list[0];
        this.itemObj = itemObj;
        const storage = window.localStorage;
        storage.setItem('itemObj', JSON.stringify(itemObj));
      }
      this.configData.list.splice(index, 1);
    }
  }
};
</script>

<style scoped lang="stylus">
    /deep/.ivu-input{
		font-size: 12px!important
	}
	/deep/.ivu-input-wrapper{
		width: 240px;
	}
	/deep/.ivu-input-word-count{
		color: #BBBBBB;
	}
	/deep/.ivu-input-icon{
		color: #BBBBBB;
	}
    .c_product{
		padding: 0 15px 20px 15px
		.list-box{
			.item{
				display: flex;
				align-items: center;
				position relative
				margin-top 23px
				padding 18px 20px 18px 0;
				background-color: #F9F9F9
				border-radius: 3px;

				.delete{
					position absolute
					right 0
					top 0
					right: -13px;
					top: -14px;
					color #ccc;
					cursor pointer
				}
			}

			.move-icon{
				display flex
				align-items center
				justify-content center
				width 50px
				cursor move
			}

			.con-item{
				display flex
				align-items center
				margin-bottom 15px

				&:last-child{
					margin-bottom 0
				}
				span{
					width 75px
					font-size 12px
					color: #999999;
				}
			}
		}

		.add-btn{
			margin-top 21px
			.btn{
				width: 100%;
				height: 36px;
				border-color:#EEEEEE;
				color: #666666;
				.iconfont{
					font-size: 11px;
					margin-right: 5px;
				}
			}
		}
	}
    .title{
		font-size: 12px
		color: #999
	}
    .iconfont-diy{
		color: #DDDDDD
		font-size: 16px
	}
</style>
