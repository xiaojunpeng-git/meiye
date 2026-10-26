<template>
  <!-- 系统表单 -->
  <div class="diy-page">
    <PageHeader
        class="product_tabs"
        :title="$route.meta.title"
        hidden-breadcrumb
    >
      <div slot="title" class="f_return acea-row row-between-wrapper">
        <div class="f_title acea-row row-middle">
          <div class="title acea-row row-middle" @click="returnTap">
            <div class="iconfont iconfanhui"></div>
            <div class="return">返回</div>
          </div>
		  <div class="mr20">
		  	<span class="name">当前页面：{{storeName}}</span>
		  	<Poptip v-model="visible" width="347">
		  		<span class="iconfont iconzidingyicaidan"></span>
		  		<template #content>
		  			<div>
		  				<Input v-model="storeName" placeholder="必填不超过15个字" style="width: 200px"></Input>
		  				<Button type="text" @click="cancel">取消</Button>
		  				<Button type="primary" @click="determine">确定</Button>
		  			</div>
		  		</template>
		  	</Poptip>
		  </div>
        </div>
        <div>
          <Button ghost class="bnt" @click="reast">重置</Button>
          <Button ghost class="bnt ml20 w-80" @click="saveConfig(1)" :loading="loading">保存</Button>
          <Button class="release ml20 w-80" @click="saveConfig(2)" :loading="relLoading">立即发布</Button>
        </div>
      </div>
    </PageHeader>
    <Card :bordered="false" dis-hover class="ivu-mt" style="margin: 0 10px;">
      <div class="diy-wrapper" :style="'height:'+ clientHeight + 'px;'">
        <!-- 左侧 -->
        <div class="left">
          <div class="wrapper" :style="'height:'+ (clientHeight-96) + 'px;'" v-if="tabCur == 0">
            <div v-for="(item, index) in leftMenu" :key="index">
              <div class="tips" @click="item.isOpen = !item.isOpen">
                {{ item.title }}
                <Icon type="ios-arrow-forward" size="16" v-if="!item.isOpen"/>
                <Icon type="ios-arrow-down" size="16" v-else/>
              </div>
              <!-- 拖拽组件 -->
              <draggable
                  class="dragArea list-group"
                  :list="item.list"
                  :group="{ name: 'people', pull: 'clone', put: false }"
                  :clone="cloneDog"
                  dragClass="dragClass"
                  filter=".search , .navbar , .homeComb , .service"
              >
                <div
                    class="list-group-item"
                    :class="{ search: element.cname == '搜索框' , navbar: element.cname == '商品分类' , homeComb: element.cname == '组合组件' , service: element.cname == '在线客服'}"
                    v-for="(element, index) in item.list"
                    :key="element.id"
                    @click="addDom(element, 1)"
                    v-show="item.isOpen"
                >
                  <div>
                    <div class="position" style="display: none">释放鼠标将组建添加到此处</div>
                    <span class="conter iconfont-diy" :class="element.icon"></span>
                    <p class="conter">{{ element.cname }}</p>
                  </div>
                </div>
              </draggable>
            </div>
          </div>
          <div class="wrapper" v-else  :style="'height:'+ (clientHeight-46) + 'px;'">
            <div
                class="link-item"
                v-for="(item, index) in urlList"
                :key="index"
            >
              <div class="name">{{ item.name }}</div>
              <div class="link-txt">地址：{{ item.url }}</div>
              <div class="params">
                <span class="txt">参数：</span>
                <span>{{ item.parameter }}</span>
              </div>
              <div class="lable">
                <p class="txt">例如：{{ item.example }}</p>
                <Button
                    size="small"
                    v-clipboard:copy="item.example"
                    v-clipboard:success="onCopy"
                    v-clipboard:error="onError"
                >复制
                </Button
                >
              </div>
            </div>
          </div>
        </div>
        <!-- 中间自定义配置移动端页面 -->
        <div
            class="wrapper-con"
            style="
            flex: 1;
            background: #f0f2f5;
            display: flex;
            justify-content: center;
            padding-top: 20px;
            height: 100%;
          "
        >
          <div class="content">
            <div
                class="contxt"
                style="
                display: flex;
                flex-direction: column;
                overflow: hidden;
                height: 100%;
              "
            >
              <div class="overflowy">
                <div class="picture"><img src="@/assets/images/electric.png"></div>
                <div
                    class="page-title"
                >
                  表单
                </div>
              </div>
              <div class="scrollCon">
                <div style="width: 460px;margin: 0 auto">
                  <div class="scroll-box" :class="picTxt&&tabValTxt==2?'fullsize noRepeat':picTxt&&tabValTxt==1?'repeat ysize':'noRepeat ysize'" :style="'background-color:'+(colorTxt?colorPickerTxt:'')+';background-image: url('+(picTxt?picUrlTxt:'')+');height:'+ rollHeight + 'px;'" ref="imgContainer">
                    <draggable
                        class="dragArea list-group"
                        :list="mConfig"
                        group="people"
                        @change="log"
                        filter=".top"
                        :move="onMove"
                        animation="300"
                    >
                      <div
                          class="mConfig-item"

                          :class="{
                      on: activeIndex == key,
                      top: item.name == 'search_box' || item.name == 'nav_bar',
                    }"
                          v-for="(item, key) in mConfig"
                          :key="key"
                          @click.stop="bindconfig(item, key)"
                          :style="colorTxt?'background-color:'+colorPickerTxt+';':'background-color:#fff;'"
                      >
                        <component
                            :is="item.name"
                            ref="getComponentData"
                            :configData="propsObj"
                            :index="key"
                            :num="item.num"
                        ></component>
                        <div class="delete-box">
                          <div class="handleType">
                            <div class="iconfont iconshanchu2" @click.stop="bindDelete(item, key)"></div>
                            <div class="iconfont iconfuzhi" @click.stop="bindAddDom(item, 0, key)"></div>
                            <div class="iconfont iconshangyi" :class="key===0?'on':''" @click.stop="movePage(item, key, 1)"></div>
                            <div class="iconfont iconxiayi" :class="key===mConfig.length-1?'on':''" @click.stop="movePage(item, key, 0)"></div>
                          </div>
                        </div>
                        <div class="handle"></div>
                      </div>
                    </draggable>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
        <!-- 右侧页面设置 -->
        <div class="right-box">
          <div class="mConfig-item" style="background-color:#fff;" v-for="(item, key) in rConfig" :key="key">
            <div class="title-bar">{{ item.cname }}</div>
            <component
                :is="item.configName"
                @config="config"
                :activeIndex="activeIndex"
                :num="item.num"
                :index="key"
            ></component>
          </div>
        </div>
      </div>
    </Card>
  </div>
</template>

<script crossorigin='anonymous'>
import {getUrl} from "@/api/diy";
import {systemForm, systemFormInfo, formUpdateName} from "@/api/setting";
import vuedraggable from "vuedraggable";
import mPage from "@/components/mobileFormPage/index.js";
import mConfig from "@/components/mobileFormConfig/index.js";
import {mapState} from "vuex";
import html2canvas from 'html2canvas';
import Setting from "@/setting";
let idGlobal = 0;
export default {
  inject: ['reload'],
  name: "index.vue",
  components: {
    html2canvas,
    draggable: vuedraggable,
    ...mPage,
    ...mConfig,
  },
  filters: {
    filterTxt(val) {
      if (val) {
        return (val = val.substr(0, val.length - 1));
      }
    },
  },
  computed: {
    ...mapState({
      nameTxt: (state) => state.admin.mobildConfig.pageName || "模板",
      showTxt: (state) => state.admin.mobildConfig.pageShow,
      colorTxt: (state) => state.admin.mobildConfig.pageColor,
      picTxt: (state) => state.admin.mobildConfig.pagePic,
      colorPickerTxt: (state) => state.admin.mobildConfig.pageColorPicker,
      tabValTxt: (state) => state.admin.mobildConfig.pageTabVal,
      picUrlTxt: (state) => state.admin.mobildConfig.pagePicUrl,
    }),
  },
  data() {
    return {
      clientHeight:'',//页面动态高度
      rollHeight:'',
      leftMenu: [], // 左侧菜单
      lConfig: [], // 左侧组件
      mConfig: [], // 中间组件渲染
      rConfig: [], // 右侧组件配置
      activeConfigName: "",
      propsObj: {}, // 组件传递的数据,
      activeIndex: 0, // 选中的下标
      number: 0,
      pageId: "",
      pageName: "",
      pageType: "",
      tabCur: 0,
      urlList: [],
      loading: false,
      relLoading: false,
      isSearch: false,
      isTab: false,
      isFllow: false,
      isComb: false,
      isService: false,
      storeName: "模板",
	  visible: true
    };
  },
  created() {
    this.pageId = this.$route.query.id;
    this.pageName = this.$route.query.name;
    this.pageType = this.$route.query.type;
    this.lConfig = this.objToArr(mPage);
  },
  mounted() {
    this.$nextTick(() => {
      this.arraySort();
      if (this.pageId != 0) {
        this.getDefaultConfig();
      }
      this.clientHeight = `${document.documentElement.clientHeight}`-65.81;//获取浏览器可视区域高度
      let H = `${document.documentElement.clientHeight}`-180;
      this.rollHeight = H>650?650:H;
      let that = this;
      window.onresize = function(){
        that.clientHeight =  `${document.documentElement.clientHeight}`-65.81;
        let H = `${document.documentElement.clientHeight}`-180;
        that.rollHeight = H>650?650:H;
      }
    });
  },
  methods: {
	cancel(){
		this.visible = false;
	},
	determine(){
		if(this.storeName.trim() == ''){
			return this.$Message.error("请输入模板名称");
		}
		if(this.pageId == 0){
			this.$Message.success('修改成功');
			return false
		}
		formUpdateName(this.pageId,{name:this.storeName}).then(res=>{
			this.$Message.success(res.msg);
		}).catch(err=>{
			this.$Message.error(err.msg);
		})
		this.visible = false;
	},
    returnTap(){
      this.$Modal.confirm({
        title:'温馨提示',
        content:'确定离开此页面？系统可能不会保存您所做的更改。',
        onOk: () => {
          this.$router.push('/admin/setting/system_form');
        },
        onCancel: () => {
          this.$Message.info('已取消');
        }
      });
    },
    leftRemove({to, from, item, clone, oldIndex, newIndex}) {
      if (this.isSearch && newIndex == 0) {
        if (item._underlying_vm_.name == "z_wechat_attention") {
          this.isFllow = true;
        } else {
          this.$store.commit(
              "admin/mobildConfig/ARRAYREAST",
              this.mConfig[0].num
          );
          this.mConfig.splice(0, 1);
        }
      }
      if (this.isFllow = true && newIndex >= 1) {
        this.$store.commit(
            "admin/mobildConfig/ARRAYREAST",
            this.mConfig[0].num
        );
      }
    },
    onMove(e) {
      if (e.relatedContext.element.name == "search_box") return false;
      if (e.relatedContext.element.name == "nav_bar") return false;
      if (e.relatedContext.element.name == "home_comb") return false;
      return true;
    },
    onCopy() {
      this.$Message.success("复制成功");
    },
    onError() {
      this.$Message.error("复制失败");
    },
    // 获取url
    getUrlList() {
      getUrl().then((res) => {
        this.urlList = res.data.url;
      });
    },
    // 左侧tab
    bindTab(index) {
      this.tabCur = index;
    },
    // 对象转数组
    objToArr(data) {
      let obj = Object.keys(data);
      let m = obj.map((key) => data[key]);
      return m;
    },
    log(evt) {
      // 中间拖拽排序
      if (evt.moved) {
        if (evt.moved.element.name == "search_box") {
          return this.$Message.warning("该组件禁止拖拽");
        }
        evt.moved.oldNum = this.mConfig[evt.moved.oldIndex].num;
        evt.moved.newNum = this.mConfig[evt.moved.newIndex].num;
        evt.moved.status = evt.moved.oldIndex > evt.moved.newIndex;
        this.mConfig.forEach((el, index) => {
          el.num = new Date().getTime() * 1000 + index;
        });
        evt.moved.list = this.mConfig;
        this.rConfig = [];
        let item = evt.moved.element;
        let tempItem = JSON.parse(JSON.stringify(item));
        this.rConfig.push(tempItem);
        this.activeIndex = evt.moved.newIndex;
        this.$store.commit("admin/mobildConfig/SETCONFIGNAME", item.name);
        this.$store.commit("admin/mobildConfig/defaultArraySort", evt.moved);
      }
      // 从左向右拖拽排序
      if (evt.added) {
        let data = evt.added.element;
        let obj = {};
        let timestamp = new Date().getTime() * 1000;
        data.num = timestamp;
        this.activeConfigName = data.name;
        let tempItem = JSON.parse(JSON.stringify(data));
        tempItem.id = "id" + tempItem.num;
        this.mConfig[evt.added.newIndex] = tempItem;
        this.rConfig = [];
        this.rConfig.push(tempItem);
        this.mConfig.forEach((el, index) => {
          el.num = new Date().getTime() * 1000 + index;
        });
        evt.added.list = this.mConfig;
        this.activeIndex = evt.added.newIndex;
        // 保存组件名称
        this.$store.commit("admin/mobildConfig/SETCONFIGNAME", data.name);
        this.$store.commit("admin/mobildConfig/defaultArraySort", evt.added);
      }
    },
    cloneDog(data) {
      return {
        ...data,
      };
    },
    //数组元素互换位置
    swapArray(arr, index1, index2) {
      arr[index1] = arr.splice(index2, 1, arr[index1])[0];
      return arr;
    },
    //点击上下移动；
    movePage(item,index,type){
      if(type){
        if(index == 0){
          return
        }
      }else {
        if(index == this.mConfig.length-1){
          return
        }
      }
      if (item.name == "search_box" || item.name == "nav_bar" || item.name == "home_comb") {
        return this.$Message.warning("该组件禁止移动");
      }
      if(type){
        if(this.mConfig[index-1].name  == "search_box" || this.mConfig[index-1].name  == "nav_bar" || this.mConfig[index-1].name  == "home_comb"){
          return this.$Message.warning("搜索框或分类或组合组件必须为顶部");
        }
        this.swapArray(this.mConfig, index-1, index);
      }else {
        this.swapArray(this.mConfig, index, index+1);
      }
      let obj = {};
      this.rConfig = [];
      obj.oldIndex = index;
      if(type){
        obj.newIndex = index-1;
      }else {
        obj.newIndex = index+1;
      }
      this.mConfig.forEach((el, index) => {
        el.num = new Date().getTime() * 1000 + index;
      });
      let tempItem = JSON.parse(JSON.stringify(item));
      this.rConfig.push(tempItem);
      obj.element = item;
      obj.list = this.mConfig;
      if(type){
        this.activeIndex = index-1;
      }else {
        this.activeIndex = index+1;
      }
      this.$store.commit("admin/mobildConfig/SETCONFIGNAME", item.name);
      this.$store.commit("admin/mobildConfig/defaultArraySort", obj);
    },
    // 组件添加
    addDomCon(item,type,index){
      if (item.name == "search_box") {
        if (this.isSearch) return this.$Message.error("该组件只能添加一次");
        if(this.isComb) return this.$Message.error("组合组件不能和搜索组件与商品分类组件同时存在");
        this.isSearch = true;
      }
      if (item.name == "nav_bar") {
        if (this.isTab) return this.$Message.error("该组件只能添加一次");
        if(this.isComb) return this.$Message.error("组合组件不能和搜索组件与商品分类组件同时存在");
        this.isTab = true;
      }
      if (item.name == "home_comb") {
        if (this.isComb) return this.$Message.error("该组件只能添加一次");
        if(this.isSearch || this.isTab) return this.$Message.error("组合组件不能和搜索组件与商品分类组件同时存在");
        this.isComb = true;
      }
      if (item.name == "home_service") {
        if (this.isService) return this.$Message.error("该组件只能添加一次");
        this.isService = true;
      }
      idGlobal += 1;
      let obj = {};
      let timestamp = new Date().getTime() * 1000;
      item.num = `${timestamp}`;
      item.id = `id${timestamp}`;
      this.activeConfigName = item.name;
      let tempItem = JSON.parse(JSON.stringify(item));
      if(item.name == "home_comb"){
        this.rConfig = [];
        this.mConfig.unshift(tempItem);
        this.activeIndex = 0;
        this.rConfig.push(tempItem);
      }else if (item.name == "search_box") {
        this.rConfig = [];
        this.mConfig.unshift(tempItem);
        this.activeIndex = 0;
        this.rConfig.push(tempItem);
      }else if (item.name == "nav_bar") {
        this.rConfig = [];
        if (this.mConfig[0]&&this.mConfig[0].name === "search_box") {
          this.mConfig.splice(1, 0, tempItem);
          this.activeIndex = 1;
        } else {
          this.mConfig.splice(0, 0, tempItem);
          this.activeIndex = 0;
        }
        this.rConfig.push(tempItem);
      }
      else {
        if(type){
          this.rConfig = [];
          this.mConfig.push(tempItem);
          this.activeIndex = this.mConfig.length - 1;
          this.rConfig.push(tempItem);
        }else {
          this.mConfig.splice(index+1, 0, tempItem);
          this.activeIndex = index;
        }
      }
      this.mConfig.forEach((el, index) => {
        el.num = new Date().getTime() * 1000 + index;
      });
      // 保存组件名称
      obj.element = item;
      obj.list = this.mConfig;
      this.$store.commit("admin/mobildConfig/SETCONFIGNAME", item.name);
      this.$store.commit("admin/mobildConfig/defaultArraySort", obj);
    },
    //中间页点击添加模块；
    bindAddDom(item, type, index) {
      let i = item;
      this.lConfig.forEach(j=>{
        if(item.name==j.name){
          i = j
        }
      });
      this.addDomCon(i,type,index);
    },
    //左边配置模块点击添加；
    addDom(item, type) {
      this.addDomCon(item,type);
    },
    // 点击显示相应的配置
    bindconfig(item, index) {
      this.rConfig = [];
      let tempItem = JSON.parse(JSON.stringify(item));
      this.rConfig.push(tempItem);
      this.activeIndex = index;
      this.$store.commit("admin/mobildConfig/SETCONFIGNAME", item.name);
    },
    // 组件删除
    bindDelete(item, key) {
      if (item.name == "search_box") {
        this.isSearch = false;
      }
      if (item.name == "nav_bar") {
        this.isTab = false;
      }
      if (item.name == "home_comb") {
        this.isComb = false;
      }
      if (item.name == "home_service") {
        this.isService = false;
      }
      this.mConfig.splice(key, 1);
      this.rConfig.splice(0, 1);
      if(this.mConfig.length != key){
        this.rConfig.push(this.mConfig[key]);
      }else {
        if(this.mConfig.length){
          this.activeIndex = key-1;
          this.rConfig.push(this.mConfig[key-1]);
        }
      }
      // 删除第几个配置
      this.$store.commit("admin/mobildConfig/DELETEARRAY", item);
    },
    // 组件返回
    config(data) {
      let propsObj = this.propsObj;
      propsObj.data = data;
      propsObj.name = this.activeConfigName;
    },
    addSort(arr, index1, index2) {
      arr[index1] = arr.splice(index2, 1, arr[index1])[0];
      return arr;
    },
    // 数组排序
    arraySort() {
      let tempArr = [];
      let basis = {
        title: "组件",
        list: [],
        isOpen: true,
      };
      this.lConfig.map((el, index) => {
        if (el.type == 0) {
          basis.list.push(el);
        }
      });
      tempArr.push(basis);
      this.leftMenu = tempArr;
    },
    diySaveDate(val,num){
      systemForm(this.pageId, {
        value: val,
        name: this.storeName,
      })
          .then((res) => {
            this.pageId = res.data.id;
            this.$Message.success(res.msg);
            let that = this;
            if(num==2){
              this.relLoading = false;
              setTimeout(function (){
                that.$router.push('/admin/setting/system_form');
              },2000)
            }else{
              this.loading = false;
            }
          })
          .catch((res) => {
            this.loading = false;
            this.relLoading = false;
            this.$Message.error(res.msg);
          });
    },
    // 保存配置
    saveConfig(num) {
      if (this.mConfig.length == 0) {
        return this.$Message.error("暂未添加任何组件，保存失败！");
      }
      if(num==1){
        this.loading = true;
      }else{
        this.relLoading = true;
      }
      let val = this.$store.state.admin.mobildConfig.defaultArray;
      this.$nextTick(function () {
        this.diySaveDate(val,num);
      })
    },
    // 获取默认配置
    getDefaultConfig() {
      systemFormInfo(this.pageId).then(({data}) => {
        this.storeName = data.info.name;
        let obj = {};
        let tempARR = [];
        let newArr = this.objToArr(data.info.value);
        function sortNumber(a, b) {
          return a.timestamp - b.timestamp;
        }
        newArr.sort(sortNumber);
        newArr.map((el, index) => {
          el.id = "id" + el.timestamp;
          this.lConfig.map((item, j) => {
            if (el.name == item.defaultName) {
              item.num = el.timestamp;
              item.id = "id" + el.timestamp;
              let tempItem = JSON.parse(JSON.stringify(item));
              tempARR.push(tempItem);
              obj[el.timestamp] = el;
              this.mConfig.push(tempItem);
              // 保存默认组件配置
              this.$store.commit("admin/mobildConfig/ADDARRAY", {
                num: el.timestamp,
                val: el,
              });
            }
          });
        });
        this.rConfig = [];
        this.activeIndex = 0;
        this.rConfig.push(this.mConfig[0]);
      });
    },
    // 重置
    reast() {
      if (this.pageId == 0) {
        this.$Message.error("新增页面，无法重置");
      } else {
        this.$Modal.confirm({
          title: "提示",
          content: "<p>是否重置当前页面数据</p>",
          onOk: () => {
            this.mConfig = [];
            this.rConfig = [];
            this.activeIndex = -99;
            this.getDefaultConfig();
          },
          onCancel: () => {
          },
        });
      }
    },
  },
  beforeDestroy() {
    this.$store.commit("admin/mobildConfig/titleUpdata", "");
    this.$store.commit("admin/mobildConfig/nameUpdata", "");
    this.$store.commit("admin/mobildConfig/showUpdata", 1);
    this.$store.commit("admin/mobildConfig/colorUpdata", 0);
    this.$store.commit("admin/mobildConfig/picUpdata", 0);
    this.$store.commit("admin/mobildConfig/pickerUpdata", "#f5f5f5");
    this.$store.commit("admin/mobildConfig/radioUpdata", 0);
    this.$store.commit("admin/mobildConfig/picurlUpdata", "");
    this.$store.commit("admin/mobildConfig/SETEMPTY");
  },
  destroyed() {
    this.$store.commit("admin/mobildConfig/titleUpdata", "");
    this.$store.commit("admin/mobildConfig/nameUpdata", "");
    this.$store.commit("admin/mobildConfig/showUpdata", 1);
    this.$store.commit("admin/mobildConfig/colorUpdata", 0);
    this.$store.commit("admin/mobildConfig/picUpdata", 0);
    this.$store.commit("admin/mobildConfig/pickerUpdata", "#f5f5f5");
    this.$store.commit("admin/mobildConfig/radioUpdata", 0);
    this.$store.commit("admin/mobildConfig/picurlUpdata", "");
    this.$store.commit("admin/mobildConfig/SETEMPTY");
  },
};
</script>

<style scoped lang="stylus">
.release.ivu-btn{
  color #1890ff
}
.mobile-config{
  padding: 15px 0 15px 15px!important;
}
.diy-page{
  /deep/.ivu-page-header{
    background-color: #1890ff;
    border-radius:0;
    padding 16px 20px 0 20px;
  }
  .f_return{
    color #fff;
    .return{
      color #fff;
    }
    .iconfont{
      color #fff;
    }
    .f_title{
	  .name {
		  font-size: 16px;
	  }
	  .iconfont{
		  margin-left: 5px;
	  }
      &:hover{
        .return{
          color rgba(255,255,255,0.8)
        }
        .iconfanhui{
          color rgba(255,255,255,0.8)
        }
      }
    }
  }
}
.ysize {
  background-size: 100%;
}
.fullsize {
  background-size: 100% 100%;
}
.repeat {
  background-repeat: repeat;
}
.noRepeat {
  background-repeat: no-repeat;
}
.wrapper-con{
  /*min-width 700px;*/
}
.defaultData{
  /*margin-left 20px;*/
  cursor pointer;
  position absolute;
  left:50%;
  margin-left:245px;
  .data{
    margin-top 20px;
    color: #282828;
    background-color: #fff;
    width 94px;
    text-align center;
    height 32px;
    line-height 32px;
    border-radius 3px;
    font-size 12px;
  }
  .data:hover{
    background-color #2d8cf0;
    color #fff
    border:0
  }
}
.overflowy{
  overflow-y scroll;
  .picture{
    width 379px;
    height 20px;
    margin 0 auto;
    background-color #fff;
  }
}
.bnt{
  width 80px!important;
  &:hover{
    border-color rgba(255,255,255,0.8);
    color rgba(255,255,255,0.8);
  }
}
.w-80{
  width 80px!important;
}
/*定义滑块 内阴影+圆角*/
::-webkit-scrollbar-thumb{
  -webkit-box-shadow: inset 0 0 6px #fff;
  display none;
}

.left:hover::-webkit-scrollbar-thumb,.right-box:hover::-webkit-scrollbar-thumb{
  display block;
}

.contxt:hover ::-webkit-scrollbar-thumb{
  display block;
}

::-webkit-scrollbar {
  width: 4px!important; /*对垂直流动条有效*/
}

.scrollCon{
  overflow-y scroll;
  overflow-x hidden;
}

.scroll-box .position{
  display block!important;
  height 40px;
  text-align center;
  line-height 40px;
  border 1px dashed #1890ff;
  color #1890ff;
  background-color #edf4fb;
}

.scroll-box .conter{
  display none!important;
}

.dragClass {
  background-color: #fff;
}

.ivu-mt {
  display: flex;
  justify-content: space-between;
  margin-top 0 !important;
}

.iconfont-diy {
  font-size: 24px;
  color: #1890ff;
}

.diy-wrapper {
  max-width: 100%;
  min-width: 1100px;
  display: flex;
  justify-content: space-between;
  /*height: 84.5vh;*/

  .left {
    min-width: 300px;
    max-width: 300px;
    /* border 1px solid #DDDDDD */
    border-radius: 4px;
    height: 100%;

    .title{
      padding 15px;
      font-size 13px;
      color #000;
      .input-add{
        margin-top 15px;
      }
    }

    .title-bar {
      display: flex;
      color: #333;
      border-bottom: 1px solid #eee;
      border-radius: 4px;
      cursor: pointer;

      .title-item {
        display: flex;
        align-items: center;
        justify-content: center;
        flex: 1;
        height: 45px;

        &.on {
          color: #1890FF;
          font-size: 14px;
          border-bottom: 1px solid #1890FF;
        }
      }
    }

    .wrapper {
      padding: 15px;
      overflow-y: scroll;
      -webkit-overflow-scrolling: touch;

      .tips {
        display: flex;
        justify-content: space-between;
        padding-bottom: 15px;
        font-size: 13px;
        color: #000;
        cursor: pointer;

        .ivu-icon {
          color: #000;
        }
      }
    }

    .link-item {
      padding: 10px;
      border-bottom: 1px solid #F5F5F5;
      font-size: 12px;
      color: #323232;

      .name {
        font-size: 14px;
        color: #1890FF;
      }

      .link-txt {
        margin-top: 2px;
        word-break: break-all;
      }

      .params {
        margin-top: 5px;
        color: #1CBE6B;
        word-break: break-all;

        .txt {
          color: #323232;
        }

        span {
          &:last-child i {
            display: none;
            color: red;
          }
        }
      }

      .lable {
        display: flex;
        margin-top: 5px;
        color: #999;

        p {
          flex: 1;
          word-break: break-all;
        }

        button {
          margin-left: 30px;
          width: 38px;
        }
      }
    }

    .dragArea.list-group {
      display: flex;
      flex-wrap: wrap;

      .list-group-item {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        width: 74px;
        height: 66px;
        margin-right: 17px;
        margin-bottom: 10px;
        font-size: 12px;
        color: #666;
        cursor: pointer;
        border-radius: 5px;
        text-align center;

        &:hover {
          box-shadow: 0 0 5px 0 rgba(24, 144, 255, 0.3);
          border-right: 5px;
        }

        &:nth-child(3n) {
          margin-right: 0;
        }
      }
    }
  }

  .content {
    position: relative;
    height: 100%;
    width 100%;

    .page-foot {
      position: relative;
      width 379px;
      margin 0 auto 20px auto;

      .delete-box {
        display: none;
        position: absolute;
        left: -2px;
        top: 0;
        width: 383px;
        height: 100%;
        border: 2px dashed #1890ff;
        padding: 10px 0;
      }

      &:hover, &.on {
        /*cursor: move;*/

        .delete-box {
          /*display: block;*/
        }
      }

      &.on {
        cursor: move;
        .delete-box {
          display: block;
          border: 2px solid #1890ff;
          box-shadow: 0 0 10px 0 rgba(24, 144, 255, 0.3);
        }
      }


    }

    .page-title {
      position: relative;
      height: 35px;
      line-height: 35px;
      background: #fff;
      font-size: 15px;
      color: #333333;
      text-align: center;
      width 379px;
      margin 0 auto;

      .delete-box {
        display: none;
        position: absolute;
        left: -2px;
        top: 0;
        width: 383px;
        height: 100%;
        border: 2px dashed #1890ff;
        padding: 10px 0;

        span {
          position: absolute;
          right: 0;
          bottom: 0;
          width: 32px;
          height: 16px;
          line-height: 16px;
          display: inline-block;
          text-align: center;
          font-size: 10px;
          color: #fff;
          background: rgba(0, 0, 0, 0.4);
          margin-left: 2px;
          cursor: pointer;
          z-index: 11;
        }
      }

      &:hover, &.on {
        /*cursor: move;*/

        .delete-box {
          /*display: block;*/
        }
      }

      &.on {
        cursor: move;
        .delete-box {
          display: block;
          border: 2px solid #1890ff;
          box-shadow: 0 0 10px 0 rgba(24, 144, 255, 0.3);
        }
      }

    }

    .scroll-box {
      flex: 1;
      background-color: #fff;
      width 379px;
      margin 0 auto;
      padding-top 1px;
    }

    .dragArea.list-group {
      width: 100%;
      height 100%;

      .mConfig-item {
        position: relative;
        cursor move;

        .delete-box {
          display: none;
          position: absolute;
          left: -2px;
          top: 0;
          width: 383px;
          height: 100%;
          border: 2px dashed #1890ff;
          /*padding: 10px 0;*/

          .handleType{
            position: absolute;
            right: -43px;
            top: 0;
            width: 36px;
            height: 143px;
            border-radius 4px;
            background-color #1890ff;
            cursor pointer;
            color #fff;
            font-weight bold;
            text-align center;
            padding 4px 0;

            .iconfont {
              padding 5px 0;
              &.on{
                opacity 0.4
              }
            }
          }
        }
        &.on {
          cursor: move;
          .delete-box {
            display: block;
            border: 2px solid #1890ff;
            box-shadow: 0 0 10px 0 rgba(24, 144, 255, 0.3);
          }
        }
      }
    }
  }

  .right-box {
    max-width: 425px;
    min-width: 425px;
    height: 100%;
    border-radius: 4px;
    overflow: scroll;
    -webkit-overflow-scrolling: touch;

    .title-bar {
      width: 100%;
      height: 45px;
      line-height: 45px;
      padding-left: 24px;
      color: #000;
      border-radius: 4px;
      border-bottom: 1px solid #eee;
      font-size: 14px;
    }
  }

  ::-webkit-scrollbar {
    width: 6px;
    background-color: transparent;
  }

  ::-webkit-scrollbar-track {
    border-radius: 10px;
  }

  ::-webkit-scrollbar-thumb {
    background-color: #bfc1c4;
  }
}

.foot-box {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 80px;
  background: #fff;
  box-shadow: 0px -2px 4px 0px rgba(0, 0, 0, 0.03);

  button {
    width: 100px;
    height: 32px;
    font-size: 13px;

    &:first-child {
      margin-right: 20px;
    }
  }
}

/deep/ .ivu-scroll-loader {
  display: none;
}

/deep/ .ivu-card-body {
  width: 100%;
  padding 0;
}
</style>