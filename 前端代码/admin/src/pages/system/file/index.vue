<template>
<!-- 装修-素材中心 -->
  <div class="Modal">
    <Row>
      <Col span="24">
        <Tabs v-model="uploadName.file_type" @on-click="onhangeTab">
          <TabPane
                  v-for="(item, index) in headTab"
                  :key="index"
                  :label="item.title"
                  :name="item.name"
          ></TabPane>
        </Tabs>
      </Col>
    </Row>
    <Row style="display:none">
      <Col span="5">
        <div class="input">
            <Input
              search
              placeholder="请输入分类名称"
              v-model="uploadName.name"
              style="width: 90%"
              @on-search="changePage"
            />
          </div>
      </Col>
      <Col span="20">
        <Button class="mr10" v-if="uploadName.file_type==1" @click="openUpload">上传图片</Button>
        <Button class="mr10" v-else @click="uploadVideo">上传视频</Button>
        <Button
          class="mr10"
          :disabled="checkPicList.length === 0"
          @click.stop="editPicList('图片')"
          >{{uploadName.file_type==1?'删除图片':'删除视频'}}</Button
        >
      </Col>
    </Row>
    <Row type="flex" justify="start">
      <Col class="Navs">
          <div class="trees">
            <Tree
              :data="treeData"
              :render="renderContent"
              :load-data="loadData"
              class="treeBox"
              ref="tree"
			  @on-toggle-expand='toggle'
            >
            </Tree>
            <div
              class="searchNo"
              v-if="searchClass && treeData.length <= 1"
            >
              此分类暂无数据
            </div>
          </div>
      </Col>
      <Col class="tableDiv">
        <div class="right-container">
          <div class="header">
            <div>
              <Button class="mr10 upload" v-if="uploadName.file_type==1" @click="openUpload">上传图片</Button>
              <Button class="mr10 upload" v-else @click="uploadVideo">上传视频</Button>
              <Button
                class="mr10"
                :disabled="checkPicList.length === 0"
                @click.stop="editPicList('图片')"
                >{{uploadName.file_type==1?'删除图片':'删除视频'}}</Button
              >
              <div class="select-wrapper">
                <Cascader v-model="cascaderValue" :placeholder="uploadName.file_type == 1 ? '图片移动至' : '视频移动至'" :data="cascaderData" :load-data="loadData" change-on-select @on-visible-change="visibleChange"></Cascader>
              </div>
            </div>
            <div>
              <div class="input-wrapper">
                <Input
                  search
                  placeholder="搜索内容"
                  v-model="uploadName.name"
                  @on-search="changePage"
                />
              </div>
              <RadioGroup v-model="layout" type="button" button-style="solid">
                <Radio :label="1">
                  <Icon custom="iconfont icongongge" size="14" />
                </Radio>
                <Radio :label="2">
                  <Icon custom="iconfont iconliebiao" size="14" />
                </Radio>
            </RadioGroup>
            </div>
          </div>
          <div class="main">
              <div v-show="isShowPic && layout == 1" class="imagesNo">
                <Icon type="ios-images" size="60" color="#dbdbdb" />
                <span class="imagesNo_sp">{{uploadName.file_type == 1?'图片库为空':'视频库为空'}}</span>
              </div>
              <transition>
              <div ref="imgListBox" v-if="layout == 1" key="grid" class="acea-row conter">
                <div
                  class="pictrueList_pic"
				  :style="{ marginLeft: picmargin,marginRight: picmargin }"
                  v-for="(item, index) in pictrueList"
                  :key="index"
                  @mouseenter="enterLeave(item)"
                  @mouseleave="enterLeave(item)"
                >
                  <p class="number" v-if="item.num > 0">
                    <Badge :count="item.num" type="error" :offset="[11, 12]">
                      <a href="#" class="demo-badge"></a>
                    </Badge>
                  </p>
                <div class="picimage">
                  <img
                    v-if="item.file_type === 1"
                    :class="item.isSelect ? 'on' : ''"
                    v-lazy="item.poster || item.satt_dir"
                    @click.stop="changImage(item, index, pictrueList)"
                  />
                  <video v-if="item.file_type === 2" :class="item.isSelect ? 'on' : ''" :src="item.att_dir" @click.stop="changImage(item, index, pictrueList)"></video>
                </div>
                  <div class="picName">
                    <p v-if="!item.isEdit" v-bind:title="item.editName">
                      {{ item.editName }}
                    </p>
                    <Input
					  :ref="'input-' + index" 
                      size="small"
                      type="text"
                      v-model="item.real_name"
                      v-else
                      @on-blur="bindTxt(item)"
                    />
					<div class="picSeek">
						<Poptip placement="bottom" trigger="hover" width="300" height="300" transfer>
						   <div class="name">
						     查看
						   </div>
						   <div class="images" slot="content">
						     <img
						      :src="item.satt_dir"
						       alt=""
						       style="display: block;width: 100%;height: 300px;object-fit: contain;"
						     />
						   </div>
						 </Poptip>
					</div>
                    <div class="picMenu">
                      <Button @click="renameTap(item,index)">
                        重命名
                      </Button>
                      <Button class="preview">
                        查看
                      </Button>
                      <Button @click="editPicList(item.att_id)" >
                        删除
                      </Button>
                    </div>
                  </div>
                  <div class="nameStyle" v-show="item.realName && item.real_name" >
                    <img v-if="item.file_type == 1" :src="item.satt_dir" />
                    <div v-else-if="item.file_type == 2" :id="`player${item.att_id}`"></div>
                  </div>
                </div>
              </div>
              <Table v-if="layout == 2" key="list" ref="selection" :columns="columns4" :data="pictrueList" @on-selection-change="selectionChange">
                <template slot-scope="{ row, index }" slot="poster">
				  <img
				    v-viewer
				    v-if="row.file_type === 1"
				    v-lazy="row.poster || row.satt_dir"
				  />
				  <video v-if="row.file_type === 2" :src="row.att_dir"></video>
				  <div>{{ row.editName }}</div>
                </template>
                <template slot-scope="{ row, index }" slot="action">
                  <Button type="text" @click="editPicList(row.att_id)">删除</Button>
                  <Button type="text" @click="rename(index)">重命名</Button>
                  <!-- <Button type="text" @click="preview(index)">查看</Button> -->
                </template>
              </Table>
              </transition>
          </div>
          <div class="footer" :class="layout == 1?'flex-between-center':'acea-row row-right'">
			<div class="flex-y-center" v-if="layout == 1">
			   <Checkbox v-model="allSelect" @on-change="selectAll">
			     <span class="text--w111-333 fw-500 fs-12">全选</span>
			   </Checkbox>
			   <span class="fs-12 text--w111-999">已选 {{ checkPicList.length }} 个</span>
			</div>
            <Page
              :total="total"
              show-elevator
              show-total
              @on-change="pageChange"
              :current="fileData.page"
              :page-size="fileData.limit"
            />
          </div>
        </div>
      </Col>
    </Row>
    <Modal
            v-model="modalVideo"
            width="1024px"
            scrollable
            footer-hide
            closable
            title="上传视频"
            :mask-closable="false"
            :z-index="9"
    >
      <uploadVideo @getVideo="getvideo" :pid = "fileData.pid"></uploadVideo>
    </Modal>
  </div>
</template>

<script>
import {
  getCategoryListApi,
  createApi,
  fileListApi,
  categoryEditApi,
  moveApi,
  fileUpdateApi
} from "@/api/uploadPictures";
import Setting from "@/setting";
import util from "@/libs/util";
import uploadVideo from "@/components/uploadVideos";
export default {
  name: "uploadPictures",
  components: {
    uploadVideo,
  },
  props: {
    isChoice: {
      type: String,
      default: "",
    },
    gridBtn: {
      type: Object,
      default: null,
    },
    gridPic: {
      type: Object,
      default: null,
    },
    isShow: {
      type: Number,
      default: 1,
    },
  },
  data() {
    return {
      searchClass: false,
      spinShow: false,
      fileUrl: Setting.apiBaseURL + "/file/upload",
      modalPic: false,
      treeData: [],
      treeData2: [],
      pictrueList: [],
      uploadData: {}, // 上传参数
      checkPicList: [],
      uploadName: {
        name: "",
        file_type:'1'
      },
      FromData: null,
      treeId: 0,
      isJudge: false,
      buttonProps: {
        type: "default",
        size: "small",
      },
      fileData: {
        pid: 0,
        page: 1,
        limit: 24,
      },
      total: 0,
      pids: 0,
      list: [],
      modalTitleSs: "",
      isShowPic: false,
      header: {},
      ids: [], // 选中附件的id集合
      headTab:[
        { title: "图片", name: "1" },
        { title: "视频", name: "2" }
      ],
      modalVideo: false,
      layout: 1,
      columns4: [
          {
              type: 'selection',
              width: 60,
              align: 'center'
          },
          {
              title: '图片名称',
              slot: 'poster',
			  minWidth: 300,
          },
          {
              title: '大小',
              key: 'att_size',
			  minWidth: 100,
          },
          {
              title: '上传时间',
              key: 'time',
			  minWidth: 200,
          },
          {
              title: '操作',
              slot: 'action',
              width: 100
          }
      ],
      cascaderData: [],
      cascaderValue: [],
	  currentTreeId: 0,
	  allSelect: false
    };
  },
  mounted() {
    let mainEl = this.$refs.imgListBox;
	let hang = parseInt((document.body.clientHeight - 340) / 180); //计算行数
	let col = parseInt(mainEl.clientWidth / 156); //计算列数
	this.fileData.limit = col * hang; //计算分页数量
	this.picmargin = parseInt(mainEl.clientWidth-17 - col * 146) / (2 * col) + 'px'; //平均分布计算margin距离
	this.getToken();
    this.getList();
    this.getFileList();
    document.addEventListener('click', event => {
      if (
        !event.target.classList.contains('nameStyle') &&
        !event.target.classList.contains('preview') &&
        !event.target.classList.contains('ivu-icon-ios-eye') &&
        !event.target.parentNode.classList.contains('nameStyle')

        ) {
          if (this.player) {
            this.player.dispose();
            this.player = null;
          }
        this.pictrueList.forEach(pic => {
          pic.realName = false;
        });
      }
    }, true);
  },
  methods: {
	renameTap(item,index){
		let position  = this.$getLastSegmentPosition(item.real_name);
		item.isEdit = !item.isEdit;
		this.$nextTick(() => {
			const inputRef = this.$refs[`input-${index}`][0];
			const nativeInput = inputRef.$el.querySelector('input');
			if (nativeInput) {
				nativeInput.focus();
				nativeInput.select();
				setTimeout(function(){
					nativeInput.setSelectionRange(0,position);
				})
			}
		});
	},
    visibleChange(value) {
      this.$nextTick(() => {
        if (!value) {
          if (this.cascaderValue.length) {
            if (this.ids.length) {
              this.pids = this.cascaderValue[this.cascaderValue.length - 1];
              this.getMove();
            } else {
              this.$Message.warning("请先选择图片");
              this.cascaderValue = [];
            }
          }
        }
      });
    },
    createPoster(data) {
      new Promise((resolve, reject) => {
        let video = document.createElement('video');
        video.setAttribute('src', data.att_dir);
        video.setAttribute('crossOrigin', 'anonymous');
        video.setAttribute('width', 100);
        video.setAttribute('height', 100);
        video.setAttribute('preload', 'auto');
        video.addEventListener('canplay', () => {
          let canvas = document.createElement('canvas');
          let context = canvas.getContext('2d');
          let width = video.width;
          let height = video.height;
          canvas.width = width;
          canvas.height = height;
          context.drawImage(video, 0, 0, width, height);
          resolve(canvas.toDataURL('image/jpeg'));
        });
      }).then(url => {
        data.poster = url;
      });
    },
    preview(index) {
      if (this.pictrueList[index].file_type == 2) {
        this.createPlayer(this.pictrueList[index]);
      }
      if (this.layout === 1) {
        if (!this.pictrueList[index].realName) {
          for (let i = 0; i < this.pictrueList.length; i++) {
            this.pictrueList[i].realName = false;
          }
        }
        this.pictrueList[index].realName = !this.pictrueList[index].realName;
      }
      if (this.layout === 2) {
        this.$refs[`sattDir${index}`].$viewer.show();
      }
    },
    rename(index) {
      this.$Modal.confirm({
          render: (h) => {
            return h('Input', {
              props: {
                  value: this.pictrueList[index].editName,
                  autofocus: true,
                  placeholder: '请输入文件名'
              },
              on: {
                  input: (val) => {
                      this.pictrueList[index].real_name = val;
                  }
              }
            })
          },
          onOk: () => {
            this.bindTxt(this.pictrueList[index]);
          }
      })
    },
    createPlayer(data) {
      if (this.player) {
        this.player.dispose();
        this.player = null;
      }
      this.player = new Aliplayer({
        id: `player${data.att_id}`,
        width: '100%',
        height: '100%',
        autoplay: true,
        source: data.att_dir,
      });
    },
    uploadVideo(){
      this.modalVideo = true;
    },
    getvideo(){
	  let that = this;
      that.modalVideo = false;
	  setTimeout(function(){
		  that.changePage();
	  },300)
    },
    onhangeTab(){
      this.getList();
      this.getFileList(1);
	  this.allSelect = false;
	  this.ids = [];
      this.checkPicList = [];
    },
    enterMouse(item) {
      item.realName = !item.realName;
    },
    enterLeave(item) {
      item.isShowEdit = !item.isShowEdit;
    },
    // 上传头部token
    getToken() {
      this.header["Authori-zation"] = "Bearer " + util.cookies.get("token");
    },
    // 树状图
    renderContent(h, { root, node, data }) {
      let dropdown = [];
      if (data.pid == 0) {
        dropdown.push(
          h(
            "DropdownItem",
            {
              props: {
                name: '1'
              },
			  style: {
				  paddingLeft:'20px'
			  }
            },
            "添加"
          )
        );
      }
      if (data.id) {
        dropdown.push(
          h(
            "DropdownItem",
            {
              props: {
                name: '2'
              },
			  style: {
				  paddingLeft:'20px'
			  }
            },
            "编辑"
          ),
          h(
            "DropdownItem",
            {
              props: {
                name: '3'
              },
			  style: {
				  paddingLeft:'20px'
			  }
            },
            "删除"
          )
        );
      }
      return h(
        "span",
        {
          style: {
            position: "relative",
            display: "inline-block",
            width: "100%",
          },
          attrs: {
            id: "tree" + data.id,
          },
          on: {
            mouseover: () => {
              data.flag = true;
              // this.onMouseOver(root, node, data);
              this.$refs.tree.$el.querySelector(`#tree${data.id}`).parentNode.parentNode.classList.add('hovering')
            },
            mouseout: () => {
              // this.onMouseOver(root, node, data);
              data.flag = false;
              this.$refs.tree.$el.querySelector(`#tree${data.id}`).parentNode.parentNode.classList.remove('hovering')
            },
            click: () => {
              this.appendBtn(root, node, data);
            },
          },
        },
        [
          h(
            "span",
            [
              h('Icon', {
                props: {
                  type: 'ios-folder'
                },
                style: {
				  color:'rgba(255, 202, 40, 1)',
                  marginRight: data.pid? '0':'8px',
                  visibility: data.pid ? 'hidden' : 'visible',
				  verticalAlign: 'baseline'
                }
              }),
              h('span', data.title)
            ]
          ),
          h(
            "Dropdown",
            {
			  props: {
			    transfer: true
			  },
              style: {
                position: 'absolute',
                top: 0,
                right: 0
              },
              on: {
                'on-click': (name) => {
                  switch (name) {
                    case '1':
                      this.append(root, node, data);
                      break;
                    case '2':
                      this.editPic(root, node, data);
                      break;
                    case '3':
                      this.remove(root, node, data, "分类");
                      break;
                    default:
                      break;
                  }
                }
              }
            },
            [
              h("Icon", {
                props: {
                  type: "ios-more",
                },
                style: {
                  display: data.flag ? "inline-block" : "none",
                  marginRight: "8px",
                  fontSize: "20px"
                },
              }),
              h(
                "DropdownMenu",
                {
                  slot: 'list'
                },
                dropdown
              ),
            ]
          ),
        ]
      );
    },

    renderContentSel(h, { root, node, data }) {
      return h(
        "div",
        {
          style: {
            display: "inline-block",
            width: "90%",
          },
        },
        [
          h("span", [
            h(
              "span",
              {
                style: {
                  cursor: "pointer",
                },
                class: ["ivu-tree-title"],
                on: {
                  click: (e) => {
                    this.handleCheckChange(root, node, data, e);
                  },
                },
              },
              data.title
            ),
          ]),
        ]
      );
    },
    // 下拉树
    handleCheckChange(root, node, data, e) {
      this.list = [];
      // this.pids = 0;
      let value = data.id;
      let title = data.title;
      this.list.push({
        value,
        title,
      });
      if (this.ids.length) {
        this.pids = value;
        this.getMove();
      } else {
        this.$Message.warning("请先选择图片");
      }
      let selected = this.$refs.reference.$el.querySelectorAll(
        ".ivu-tree-title-selected"
      );
      for (let i = 0; i < selected.length; i++) {
        selected[i].className = "ivu-tree-title";
      }
      e.path[0].className = "ivu-tree-title  ivu-tree-title-selected"; // 当前点击的元素
    },
    // 移动分类
    getMove() {
      let data = {
        pid: this.pids,
        images: this.ids.toString(),
      };
      moveApi(data)
        .then(async (res) => {
          this.$Message.success(res.msg);
          this.getFileList();
          this.pids = 0;
		  this.allSelect = false;
          this.checkPicList = [];
          this.ids = [];
          this.cascaderValue = [];
        })
        .catch((res) => {
          this.$Message.error(res.msg);
          this.cascaderValue = [];
        });
    },
    // 删除图片
    editPicList(tit) {
      let ids = {
        ids: this.ids.toString(),
      };
      if(typeof(tit) == 'number') {
        ids = {
          ids: tit.toString(),
        };
      }
      let delfromData = {
        title: this.uploadName.file_type==1?"删除选中图片":"删除选中视频",
        url: `file/file/delete`,
        method: "POST",
        ids: ids,
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
          this.getFileList();
		  this.allSelect = false;
          this.checkPicList = [];
		  this.ids = [];
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 鼠标移入 移出
    onMouseOver(root, node, data) {
      event.preventDefault();
      data.flag = !data.flag;
      // if (data.flag2) {
      //   data.flag2 = false;
      // }
    },
    onClick(root, node, data) {
      data.flag2 = !data.flag2;
    },
	toggle(e){
		this.$nextTick(() => {
			this.$refs.tree.$el.querySelector(`#tree${this.currentTreeId}`).parentNode.parentNode.classList.add('selected');
		});
	},
    // 点击树
    appendBtn(root, node, data) {
      let treeEl = this.$refs.tree.$el;
      let id = data.id || 0;
	  if(treeEl.querySelector('.selected')){
		  treeEl.querySelector('.selected').classList.remove('selected');
	  }
      treeEl.querySelector(`#tree${data.id}`).parentNode.parentNode.classList.add('selected');
	  this.treeId = id;
	  this.currentTreeId = id;
      this.fileData.page = 1;
      this.getFileList();
    },
    // 点击添加
    append(root, node, data) {
      this.treeId = data.id;
      this.getFrom(1);
    },
    // 删除分类
    remove(root, node, data, tit) {
      this.tits = tit;
      let delfromData = {
        title: "删除 [ " + data.title + " ] " + "分类",
        url: `file/category/${data.id}`,
        method: "DELETE",
        ids: "",
      };
      this.$modalSure(delfromData)
        .then((res) => {
          this.$Message.success(res.msg);
		  // num=0时代表当前被操作的那一行数据是未被选中；num=1是被操作和被选中的是同一条数据
		  let num = (data.selected || data.id==this.treeId)?1:0;
		  if(num){
			  let treeEl = this.$refs.tree.$el;
			  if(treeEl.querySelector('.selected')){
				  treeEl.querySelector('.selected').classList.remove('selected');
			  }
		  }
          this.getList(!num);
		  this.getFileList(num);
		  this.allSelect = false;
		  this.ids = [];
          this.checkPicList = [];
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    // 编辑树表单
    editPic(root, node, data) {
      this.$modalForm(categoryEditApi(data.id,{file_type:this.uploadName.file_type})).then(() => this.getList(1));
    },
    // 搜索分类
    changePage() {
      this.fileData.page = 1;
      this.getFileList();
	  this.allSelect = false;
      this.checkPicList = [];
	  this.ids = [];
    },
    // 分类列表树
    getList(num) {
      let data = {
        title: this.uploadName.file_type==1?'全部图片':'全部视频',
        id: "",
        pid: 0,
      };
      getCategoryListApi(this.uploadName)
        .then(async (res) => {
          let list = res.data.list;
          let categories = [data, ...list];
          categories.forEach((value, index) => {
            value.flag = false;
            value.selected = !index;
            value.label = value.title;
            value.value = value.id;
          });
          this.treeData = categories;
		  if(!num){
			  this.$nextTick(() => {
				if(this.$refs.tree.$el.querySelector('.selected')){
					this.$refs.tree.$el.querySelector('.selected').classList.remove('selected');
				}
			    this.$refs.tree.$el.querySelector(`#tree${categories[0].id}`).parentNode.parentNode.classList.add('selected');
			  });
		  }
          this.cascaderData = JSON.parse(JSON.stringify(categories));
          this.cascaderData.shift();
          if (type !== "search") {
            this.treeData2 = [...this.treeData];
          } else {
            this.searchClass = true;
          }
          this.addFlag(this.treeData);
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    loadData(item, callback) {
      item.loading = true;
      getCategoryListApi({
        pid: item.id,
        file_type: this.uploadName.file_type
      })
        .then(async (res) => {
          let list = res.data.list;
          let categories = list.map((value) => {
            return {
              ...value,
              label: value.title,
              value: value.id,
              flag: false,
            };
          });
          item.loading = false;
          if (Object.hasOwnProperty.call(item, 'nodeKey')) {
            callback(categories);
          } else {
            item.children = categories;
            callback();
          }
        })
        .catch((res) => {});
    },
    addFlag(treedata) {
      treedata.map((item) => {
        this.$set(item, "flag", false);
        this.$set(item, "flag2", false);
        item.children && this.addFlag(item.children);
      });
    },
    // 新建分类
    add() {
      this.treeId = 0;
      this.getFrom();
    },
    // 文件列表
    getFileList(num) {
      this.fileData.pid = num?0:this.treeId;
      this.fileData.file_type = this.uploadName.file_type;
      this.fileData.name = this.uploadName.name;
      fileListApi(this.fileData)
        .then(async (res) => {
          res.data.list.forEach((el) => {
            el._checked = false;
            el.isSelect = false;
            el.isEdit = false;
            el.isShowEdit = false;
            el.realName = false;
            el.num = 0;
            this.editName(el);
          });
          this.pictrueList = res.data.list;

          if (this.pictrueList.length) {
            this.isShowPic = false;
          } else {
            this.isShowPic = true;
          }
          this.total = res.data.count;
        })
        .catch((res) => {
          this.$Message.error(res.msg);
        });
    },
    pageChange(index) {
      this.fileData.page = index;
      this.getFileList();
	  this.allSelect = false;
      this.checkPicList = [];
	  this.ids = [];
    },
    // 新建分类表单
    getFrom(num) {
      this.$modalForm(createApi({ id: this.treeId, file_type: this.uploadName.file_type })).then((res) => {
		this.getList(num);
      });
    },
    // 上传之前
    beforeUpload(res) {
      // if (this.uploadList.length > 4) {
      //   // this.$Message.warning("一次最多只能上传5张图片");
      //   return false;
      // }
       //控制文件上传格式
      let imgTypeArr = ["image/png", "image/jpg", "image/jpeg","image/gif"];
      let imgType = imgTypeArr.indexOf(res.type) !== -1
      if (!imgType) {
        this.$Message.warning({
          content:  '文件  ' + res.name + '  格式不正确, 请选择格式正确的图片',
          duration: 5
        });
        return false
      }
      // 控制文件上传大小
      let imgSize = this.$cache.local.getJSON('file_size_max');
      let Maxsize = res.size  < imgSize;
      let fileMax = imgSize/ 1024 / 1024;
      if (!Maxsize) {
        this.$Message.warning({
          content: '文件体积过大,图片大小不能超过' + fileMax + 'M',
          duration: 5
        });
        return false
      }
      this.uploadData = {
        pid: this.treeId,
      };
      let promise = new Promise((resolve) => {
        this.$nextTick(function () {
          resolve(true);
        });
      });
      return promise;
    },
    // 上传成功
    handleSuccess(res, file, fileList) {
      if (res.status === 200) {
        // this.uploadList = [];
        this.fileData.page = 1;
        this.$Message.success(res.msg);
        this.getFileList();
      } else {
        this.$Message.error(res.msg);
      }
    },
    // 关闭
    cancel() {
      this.$emit("changeCancel");
    },
    selectionChange(selection) {
      for (let i = 0; i < this.pictrueList.length; i++) {
        this.pictrueList[i].isSelect = this.pictrueList[i]._checked = false;
        this.pictrueList[i].num = 0;
        for (let j = 0; j < selection.length; j++) {
          if (this.pictrueList[i].att_id === selection[j].att_id) {
            this.pictrueList[i].isSelect = this.pictrueList[i]._checked = true;
            this.pictrueList[i].num = j + 1;
            break;
          }
        }
      }
      this.checkPicList = selection;
      this.ids = this.checkPicList.map(value => value.att_id);
    },
    // 选中图片
    changImage(item, index, row) {
	  if(this.allSelect){
	    this.allSelect = false;
	  }
      let activeIndex = 0;
      item.isSelect = !item.isSelect;
      item._checked = item.isSelect;
      this.checkPicList = this.pictrueList.filter(value => {
          return value.isSelect;
      });
      this.ids = [];
      this.checkPicList.map((item, i) => {
        this.ids.push(item.att_id);
      });
      this.pictrueList.map((el, i) => {
        if (el.isSelect) {
          this.checkPicList.filter((el2, j) => {
            if (el.att_id == el2.att_id) {
              el.num = j + 1;
            }
          });
        } else {
          el.num = 0;
        }
      });
	  if(this.checkPicList.length == this.pictrueList.length){
		  this.allSelect = true;
	  }else{
		  this.allSelect = false;
	  }
    },
    // 点击使用选中图片
    checkPics() {
      if (this.isChoice === "单选") {
        if (this.checkPicList.length > 1)
          return this.$Message.warning("最多只能选一张图片");
        this.$emit("getPic", this.checkPicList[0]);
      } else {
        let maxLength = this.$route.query.maxLength;
        if (
          maxLength != undefined &&
          this.checkPicList.length > Number(maxLength)
        )
          return this.$Message.warning("最多只能选" + maxLength + "张图片");
        this.$emit("getPicD", this.checkPicList);
      }
    },
    editName(item) {
      let it = item.real_name.split(".");
      let it1 = it[1] == undefined ? [] : it[1];
      let len = it[0].length + it1.length;
      item.editName = item.real_name;
    },
    // 修改图片文字上传
    bindTxt(item) {
      if (item.real_name == "") {
        this.$Message.error("请填写内容");
      }
      fileUpdateApi(item.att_id, {
        real_name: item.real_name,
      })
        .then((res) => {
          this.editName(item);
          this.$Message.success(res.msg);
		  setTimeout(function(){
			 if(item.isEdit){
				 item.isEdit = false;
			 } 
		  },150)
        })
        .catch((error) => {
          this.$Message.error(error.msg);
        });
    },
    openUpload() {
      this.$uploadImg({
        categories: this.treeData,
        categoryId: this.treeId,
        onClose: () => {
          this.fileData.page = 1;
          this.getFileList();
		  this.allSelect = false;
		  this.checkPicList = [];
		  this.ids = [];
        },
      });
    },
	selectAll() {
	  // 检查是否已经全选
	  const isAllSelected = this.pictrueList.every(item => item.isSelect);
	  
	  if (isAllSelected) {
	    // 如果已经全选，则取消全选
	    this.checkPicList = [];
	    this.ids = [];
	    
	    this.pictrueList.forEach((item) => {
	      item.isSelect = false;
	      item.num = 0;
	    });
	  } else {
	    // 如果没有全选，则执行全选
	    this.checkPicList = [];
	    this.ids = [];
	    
	    // 遍历所有图片/视频
	    this.pictrueList.forEach((item, index) => {
	      // 设置选中状态
	      item.isSelect = true;
	      // 添加到选中列表
	      this.checkPicList.push(item);
	      // 添加到ids数组
	      this.ids.push(item.att_id);
	    });
	    
	    // 更新序号
	    this.pictrueList.map((el, i) => {
	      if (el.isSelect) {
	        this.checkPicList.filter((el2, j) => {
	          if (el.att_id == el2.att_id) {
	            el.num = j + 1;
	          }
	        });
	      } else {
	        el.num = 0;
	      }
	    });
	  }
	},
  },
};
</script>

<style scoped lang="stylus">
.tableDiv {
  width: calc(100% - 240px);
}
.Navs {
  width: 100%;
  width: 220px;
  margin-right: 20px;
}

.searchNo {
  margin-top: -250px;
  text-align: center;
}

.nameStyle {
  position: absolute;
  white-space: nowrap;
  z-index: 999;
  background: #eee;
  left: -100px;
  height: 300px;
  width: 300px;
  color: #555;
  border: 1px solid #ebebeb;
  padding:0 !important;
}
.nameStyle img{
  position: absolute;
  white-space: nowrap;
  width: 99%;
  height: 99%;
  object-fit: contain;
}

.iconbianji1 {
  font-size: 13px;
}

/deep/.ivu-badge-count {
  margin-top: 18px !important;
  margin-right: 19px !important;
  background: #1890ff;
}

/deep/.ivu-btn-icon-only.ivu-btn-small {
  padding: unset !important;
}
.treeBox {
  width: 100%;
  height: 100%;

  /deep/ul li {
    padding-left: 4px;
    margin: 0;
    &.selected {
      background-color: #F1F9FF;

      .ivu-tree-title {
        color: #1890FF;
      }
    }

    &.hovering {
      background-color: #F1F9FF;
    }
  }

  /deep/.ivu-tree-arrow {
    line-height: 36px;
    color: #626262;
  }

  >>> .ivu-span:hover {
    // background: #F5F5F5;
    color: rgba(0, 0, 0, 0.4) !important;

  }

  /deep/.ivu-tree-title {
    width: calc(100% - 21px);
    line-height: 43px;
    color: #626266;
  }

   >>> .ivu-tree-title .ivu-span>span {
    padding: 5px 7px;
  }

  /deep/.ivu-tree-title-selected, .ivu-tree-title-selected:hover {
    background-color: transparent;
  }

  >>> .ivu-btn-icon-only {
    width: 20px !important;
    height: 20px !important;
  }

  >>> .ivu-tree-title:hover {
    // color: #2D8cF0 !important;
    background-color: transparent !important;
  }
}

.trees-coadd {
  width: 100%;
  border-radius: 4px;
  overflow: hidden;
  position: relative;

  .scollhide {
    overflow-x: hidden;
    overflow-y: scroll;
    padding: 0;
    box-sizing: border-box;

    .trees {
      width: 100%;
      min-height: 690px;
    }
  }

  .scollhide::-webkit-scrollbar {
    width: 4px !important; /* 对垂直流动条有效 */
  }

  /* 定义滑块 内阴影+圆角 */
  ::-webkit-scrollbar-thumb {
    -webkit-box-shadow: inset 0 0 6px #999;
  }
}

.treeSel >>>.ivu-select-dropdown-list {
  padding: 0 5px !important;
  box-sizing: border-box;
  width: 200px;
}

.imagesNo {
  display: flex;
  justify-content: center;
  flex-direction: column;
  align-items: center;
  margin: 65px auto;

  .imagesNo_sp {
    font-size: 13px;
    color: #dbdbdb;
    line-height: 3;
  }
}

.Modal {
  width: 100%;
  min-height: 690px;
  background: #fff !important;
  padding:20px
}

.colLeft {
  padding-right: 0 !important;
  height: 100%;
}

.conter .bnt {
  width: 100%;
  padding: 0 13px 0 12px;
  box-sizing: border-box;
}
.conter .pictrueList_pic {
  position: relative;
  width: 146px;
  margin: 0 19px 22px 0;
  cursor: pointer;

  .picimage {
    width: 146px;
    height: 146px;
    background-color: #f0f0f0 !important;
  }
  .picimage img,
  .picimage video {
    width: 100%;
    height: 100%;
    object-fit: contain;
	padding: 3px;
  }
  .picName {
    display: flex;
    align-items: center;
    justify-content: space-between;
    background-color: #fff !important;
    padding: 1px 0 15px;
    /deep/.ivu-input {
      height: 24px;
    }
  }
  .picSeek{
	  position: absolute
	  bottom: -2px
	  text-align: center
	  z-index: 4
	  left: 46px
	  .name{
		  font-size: 12px;
		  opacity: 0;
	  }
  }
  .picName .picMenu {
    position: absolute
    bottom: 0
    left: 0
    display:none
    font-size: 0;
	
    .ivu-btn {
      height: 14px;
      padding: 0;
      border: 0;
      line-height: 14px;
      color: #1890FF;
      +.ivu-btn {
        margin-left: 10px;
      }
      &:focus {
        box-shadow: none;
      }
    }
  }
  .picName:hover .picMenu{
    display:block
  }

  .picimage:hover +.picName .picMenu{
    display:block
  }


  p {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    font-size: 12px;
    line-height: 24px;
    text-align: center;
    color: #515A6D;
  }

  .number {
    height: 33px;
  }

  .number {
    position: absolute;
    right: 0;
    top: 0;
  }
}

.conter .pictrueList {
  width: 100%;
  height: 100%;
}

.conter .pictrueList img {
  width: 100%
  vertical-align: middle;
}

.conter .pictrueList img.on {
  border: 2px solid #5FB878;
}

.conter .footer {
  padding: 10px 0;
}

.demo-badge {
  width: 42px;
  height: 42px;
  background: transparent;
  border-radius: 6px;
  display: inline-block;
}

.bnt /deep/ .ivu-tree-children {
  padding: 5px 0;
}

.trees-coadd /deep/ .ivu-tree-children .ivu-tree-arrow {
  line-height: 18px;
}
/deep/.ivu-tabs-bar {
  border-bottom-color: #EEEEEE;
  margin-bottom: 0;
}
.trees {
  height: 100%;
  padding: 20px 20px 20px 0;
  border-right: 1px solid #EEEEEE;
}
.right-container {
  .header {
    display: flex;
    justify-content: space-between;
    padding: 20px 20px 20px 0;
    .ivu-btn {
      width: 78px;
      &.upload {
        border-color: #2D8CF0;
        background: #2D8CF0;
        color: #FFFFFF;
      }
    }
    .ivu-radio-wrapper {
      padding: 0 10px;
    }
  }
  .select-wrapper {
    display: inline-block;
    width: 160px;
  }
  .input-wrapper {
    display: inline-block;
    width: 220px;
    margin-right: 14px;
  }
  .main {
	min-height: 500px;
    .ivu-table-wrapper {
      margin-bottom: 22px;
    }
  }
  .ivu-table-cell {
    img,video {
      float: left;
      width: 30px;
      height: 30px;
      object-fit: contain;
      + div {
        margin-left: 50px;
      }
    }
    .ivu-btn {
      height: auto;
      padding: 0;
      border: 0;
      color: #1890FF;
      &:focus {
        box-shadow: none;
      }
      &:hover {
        background-color: transparent;
      }
      + .ivu-btn {
        margin-left: 10px;
      }
    }
  }
}
/deep/.ivu-page-options-elevator input {
  text-align: center;
}
</style>
