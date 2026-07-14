<template>
  <div class="users">
    <div class="acea-row row-top content">
      <div class="left" :style="colorStyle">
        <div class="phone">
          <Tooltip
            :class="{ 'active-tip': active === 0 }"
            content="会员信息"
            placement="left-start"
            always
            theme="light"
          >
            <Member
              class="cup module"
              :class="{ active: active === 0 }"
              :merType="memberData.style"
              @click.native="editMode(0)"
            ></Member>
          </Tooltip>
          <Tooltip
            :class="{ 'active-tip': active === 1 }"
            content="订单中心"
            placement="left-start"
            always
            theme="light"
          >
            <Order
              class="cup module"
              :class="{ active: active === 1 }"
              :orderType="orderData.style"
              @click.native="editMode(1)"
            ></Order>
          </Tooltip>
          <Tooltip
            :class="{ 'active-tip': active === 2 }"
            content="运营统计"
            placement="left-start"
            always
            theme="light"
          >
            <OrderStatic
              class="cup module"
              :class="{ active: active === 2 }"
              :orderStaticType="orderStaticData.style"
              @click.native="editMode(2)"
            ></OrderStatic>
          </Tooltip>
          <Tooltip
            :class="{ 'active-tip': active === 3 }"
            content="广告位"
            placement="left-start"
            always
            theme="light"
          >
            <Poster
              class="cup module"
              :class="{ active: active === 3 }"
              :orderStaticType="posterData.poster"
              @click.native="editMode(3)"
            ></Poster>
          </Tooltip>
          <Tooltip
            :class="{ 'active-tip': active === 4 }"
            content="我的服务"
            placement="left-start"
            always
            theme="light"
          >
            <Menus
              class="cup module"
              :class="{ active: active === 4 }"
              :menuType="menuData.style"
              @click.native="editMode(4)"
            ></Menus>
          </Tooltip>
          <Tooltip
            :class="{ 'active-tip': active === 5 }"
            content="平台管理"
            placement="left-start"
            always
            theme="light"
          >
            <MerMenus
              class="cup module"
              :class="{ active: active === 5 }"
              :merAdminType="merMenuData.style"
              @click.native="editMode(5)"
            ></MerMenus>
          </Tooltip>
		  <Tooltip
		    :class="{ 'active-tip': active === 7 }"
		    content="区域管理"
		    placement="left-start"
		    always
		    theme="light"
		  >
		    <AgentMenus
		      class="cup module"
		      :class="{ active: active === 7 }"
		      :agentAdminType="agentMenuData.style"
		      @click.native="editMode(7)"
		    ></AgentMenus>
		  </Tooltip>
		  <Tooltip
		    :class="{ 'active-tip': active === 6 }"
		    content="门店管理"
		    placement="left-start"
		    always
		    theme="light"
		  >
		    <StoreMenus
		      class="cup module"
		      :class="{ active: active === 6 }"
		      :storeAdminType="storeMenuData.style"
		      @click.native="editMode(6)"
		    ></StoreMenus>
		  </Tooltip>
        </div>
      </div>
      <div class="right">
        <MemberConfig v-if="active === 0"></MemberConfig>
        <OrderConig v-if="active === 1"></OrderConig>
        <OrderStaticConfig v-if="active === 2"></OrderStaticConfig>
        <PosterConfig v-if="active === 3"></PosterConfig>
        <MenuConfig v-if="active === 4"></MenuConfig>
        <MerMenuConfig v-if="active === 5"></MerMenuConfig>
		<AgentMenuConfig v-if="active === 7"></AgentMenuConfig>
		<StoreMenuConfig v-if="active === 6"></StoreMenuConfig>
      </div>
    </div>
    <Card
      :bordered="false"
      dis-hover
      class="fixed-card"
      :style="{ left: `${!menuCollapse ? '236px' : isMobile ? '0' : '60px'}` }"
    >
      <div class="acea-row row-center-wrapper">
        <Button
          class="bnt"
          type="primary"
          @click="submit"
          :loading="loadingExist"
          >保存</Button
        >
      </div>
    </Card>
  </div>
</template>

<script>
import { getMember, memberSave, newcomerList } from "@/api/diy";
import uploadPic from "./components/uploadPic";
import Member from "./template/Member/index.vue";
import Order from "./template/Order/index.vue";
import OrderStatic from "./template/OrderStatic/index.vue";
import Poster from "./template/Poster/template.vue";
import Menus from "./template/Menus/index.vue";
import MerMenus from "./template/MerMenus/index.vue";
import AgentMenus from "./template/AgentMenus/index.vue";
import StoreMenus from "./template/StoreMenus/index.vue";
import MemberConfig from "./templateConfig/MemberConfig.vue";
import OrderConig from "./templateConfig/OrderConfig.vue";
import OrderStaticConfig from "./templateConfig/OrderStaticConfig.vue";
import PosterConfig from "./templateConfig/PosterConfig.vue";
import MenuConfig from "./templateConfig/MenuConfig.vue";
import MerMenuConfig from "./templateConfig/MerMenuConfig.vue";
import AgentMenuConfig from "./templateConfig/AgentMenuConfig.vue";
import StoreMenuConfig from "./templateConfig/StoreMenuConfig.vue";
import { mapState } from "vuex";
export default {
  name: "users",
  components: {
    uploadPic,
    Member,
    Order,
    OrderStatic,
    Poster,
    Menus,
    MerMenus,
	AgentMenus,
	StoreMenus,
    MemberConfig,
    OrderConig,
    OrderStaticConfig,
    PosterConfig,
    MenuConfig,
    MerMenuConfig,
	AgentMenuConfig,
	StoreMenuConfig
  },
  props: {},
  data() {
    return {
      active: 0,
      merType: 4,
      orderType: 2,
      orderStaticType: 2,
      menuType: 3,
      loadingExist: false,
      current: 1,
      colorStyle: "",
      order: {},
      newList: [],
    };
  },
  computed: {
    ...mapState("admin/layout", ["menuCollapse", "isMobile"]),
    memberData() {
      return this.$store.state.admin.userTemplateConfig.member;
    },
    orderData(val) {
      return this.$store.state.admin.userTemplateConfig.order;
    },
    orderStaticData(val) {
      return this.$store.state.admin.userTemplateConfig.orderStatic;
    },
    posterData(val) {
      return this.$store.state.admin.userTemplateConfig.poster;
    },
    menuData(val) {
      return this.$store.state.admin.userTemplateConfig.menu;
    },
    merMenuData(val) {
      return this.$store.state.admin.userTemplateConfig.merMenu;
    },
	agentMenuData(val) {
	  return this.$store.state.admin.userTemplateConfig.agentMenu;
	},
	storeMenuData(val) {
	  return this.$store.state.admin.userTemplateConfig.storeMenu;
	},
  },
  created() {
    this.$store.dispatch("admin/userTemplateConfig/getMember");
  },
  methods: {
    submit() {
      let reqData = {
        member: this.$store.state.admin.userTemplateConfig,
        routine_my_banner:
          this.$store.state.admin.userTemplateConfig.poster.list,
        routine_my_menus: [
          ...this.$store.state.admin.userTemplateConfig.menu.list,
          ...this.$store.state.admin.userTemplateConfig.merMenu.list,
		  ...this.$store.state.admin.userTemplateConfig.agentMenu.list,
		  ...this.$store.state.admin.userTemplateConfig.storeMenu.list,
        ],
      };
	  let myBanner = reqData.routine_my_banner,
	      member = reqData.member,
		  menu = member.menu.list,
		  merMenu = member.merMenu.list,
		  agentMenu = member.agentMenu.list,
		  storeMenu = member.storeMenu.list
	  if(myBanner.length && member.poster.is_show==1){
		for (let i = 0; i < myBanner.length; i++) {
			if(!myBanner[i].name){
			   return this.$Message.error('请输入广告位标题')
			}
			if(!myBanner[i].pic){
			   return this.$Message.error('请输入广告位图片')
			}
			if(!myBanner[i].url){
			   return this.$Message.error('请输入广告位链接')
			}
		}
	  }
	  if(member.menu.is_show == 1 && menu.length){
		  for (let i = 0; i < menu.length; i++) {
		  	if(!menu[i].name){
		  	   return this.$Message.error('请输入我的服务标题')
		  	}
		  	if(!menu[i].pic){
		  	   return this.$Message.error('请输入我的服务图片')
		  	}
		  	if(!menu[i].url){
		  	   return this.$Message.error('请输入我的服务链接')
		  	}
		  }
	  }
	  if(member.merMenu.is_show == 1 && merMenu.length){
		for (let i = 0; i < merMenu.length; i++) {
			if(!merMenu[i].name){
			   return this.$Message.error('请输入平台管理标题')
			}
		}
	  }
	  if(member.agentMenu.is_show == 1 && agentMenu.length){
		for (let i = 0; i < agentMenu.length; i++) {
			if(!agentMenu[i].name){
			   return this.$Message.error('请输入区域管理标题')
			}
		}
	  }
	  if(member.storeMenu.is_show == 1 && storeMenu.length){
		for (let i = 0; i < storeMenu.length; i++) {
			if(!storeMenu[i].name){
			   return this.$Message.error('请输入门店管理标题')
			}
		}
	  }
      memberSave(reqData).then((res) => {
        this.$Message.success(res.msg);
      });
    },
    editMode(type) {
      this.active = type;
    },
  },
};
</script>
<style scoped lang="stylus">
/* 定义滑块 内阴影+圆角 */
::-webkit-scrollbar-thumb {
  -webkit-box-shadow: inset 0 0 6px #ddd;
}

::-webkit-scrollbar {
  width: 0px !important; /* 对垂直流动条有效 */
}

.content {
  display: flex;
  min-height: 600px;
  background-color: #F0F2F5;
  padding-right: 400px;

  .left {
    flex-direction: column;
    margin: 0 auto;

    // height: calc(100vh - 175px);
    // overflow-y: scroll;
    .module {
      border: 2px solid #F5F5F5;
    }

    .module.active {
      border: 2px solid #1890FF;
    }

    .phone {
      margin-top: 76px;
      width: 375px;
      padding-bottom: 30px;
      background-color: #F5F5F5;

      /deep/ .ivu-tooltip {
        width: 100%;
      }
    }
  }

  .right {
    width: 410px;
    position: fixed;
    right: 20px;
    top: 84px;
    // max-height: calc(100vh - 174px);
    height: 100%;
    overflow-y: scroll;
    background-color: #fff;
    padding-bottom: 150px;

    /deep/ .main-content {
      border-top: 6px solid #F0F2F5;
    }
  }
}

.fixed-card {
  position: fixed;
  right: 0;
  bottom: 0;
  left: 200px;
  z-index: 99;
  box-shadow: 0 -1px 2px rgb(240, 240, 240);
  padding-right: 400px;
}

/deep/ .ivu-tooltip-inner {
  box-shadow: none;
}

/deep/ .active-tip .ivu-tooltip-light .ivu-tooltip-inner {
  background-color: #1890FF;
  color: #fff;
}

/deep/ .ivu-tooltip-light.ivu-tooltip-popper {
  z-index: 10;
}

/deep/ .active-tip .ivu-tooltip-light.ivu-tooltip-popper[x-placement^='left'] .ivu-tooltip-arrow:after {
  border-left-color: #1890FF;
}

/deep/ .main {
  position: relative;
}

/deep/ .mask {
  position: absolute;
  top: 0;
  left: 0;
  width: 100%;
  height: 100%;
  background: rgba(0, 0, 0, 0.5);
  color: #fff;
  z-index: 9;
  display: flex;
  align-items: center;
  justify-content: center;
}
</style>