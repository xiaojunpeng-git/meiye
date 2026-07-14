<template>
	<div>
		<Card :bordered="false" dis-hover class="cardCon">
			<Tabs v-model="currentTab" @on-click="onClickTab">
				<TabPane :label="'小票打印'" name="store_printing_deploy" />
				<TabPane :label="'电子面单'" name="store_electronic_sheet" />
			</Tabs>
		</Card>
		<Card :bordered="false" dis-hover class="cardCon">
			<form-create :option="option" :rule="rules" @submit="onSubmit"></form-create>
			<Spin size="large" fix v-if="spinShow"></Spin>
		</Card>
	</div>
</template>

<script>
	import {
		configEdit
	} from '@/api/setting';
	import request from '@/plugins/request';
  const exampleImage = {
    store_terminal_number: '/statics/system/yilianyunPrinter.png',
    store_config_export_siid: '/statics/system/kuadi100Dump.png'
  };
  const appUrl = process.env.VUE_APP_API_URL.replace('storeapi', '');
	export default {
		name: 'index',
		data() {
			return {
				spinShow: false,
				currentTab: 'store_printing_deploy',
				rules: [],
				option: {
					form: {
						labelWidth: 185
					},
					submitBtn: {
						col: {
							span: 3,
							push: 3
						}
					},
					global: {
						upload: {
							props: {
								onSuccess(res, file) {
									if (res.status === 200) {
										file.url = res.data.src;
									} else {
										this.$Message.error(res.msg);
									}
								}
							}
						},
						frame: {
							props: {
								closeBtn: false,
								okBtn: false
							}
						}
					}
				},
			}
		},
		mounted() {
			this.getInfo();
		},
		methods: {
			onClickTab() {
				this.getInfo();
			},
			getInfo() {
				this.spinShow = true;
				let data = {
					name: this.currentTab
				}
				configEdit(data).then(res => {
					if (res.data.status === false) {
						return this.$authLapse(res.data);
					}
          for (let i = 0; i < res.data.rules.length; i++) {
            if (!['store_terminal_number', 'store_config_export_siid'].includes(res.data.rules[i].field)) {
              res.data.rules[i].suffix = {
                type: 'div',
                style: {
                  marginTop: '14px',
                  fontSize: '12px',
                  lineHeight: '16px',
                  color: '#999999'
                },
                children: [
                  res.data.rules[i].info
                ]
              };
            }else{
				res.data.rules[i].suffix = {
				  type: 'div',
				  style: {
				    marginTop: '14px',
				    fontSize: '12px',
				    lineHeight: '16px',
				    color: '#999999'
				  },
				  children: [
				    res.data.rules[i].info,
				    {
				      type: 'Poptip',
				      props: {
				        placement: 'bottom',
				        trigger: 'hover',
				        transfer: true,
				        width: 256,
				        padding: '8px'
				      },
				      children: [
				        {
				          type: 'a',
				          children: ['查看示例']
				        },
				        {
				          type: 'div',
				          class: 'exampleImg',
				          slot: 'content',
				          children: [
				            {
				              type: 'img',
				              attrs: {
				                src: `${appUrl}${exampleImage[res.data.rules[i].field]}`
				              },
				              style: {
				                display: 'block',
				                width: '100%'
				              }
				            }
				          ]
				        }
				      ]
				    }
				  ]
				};
			}
            
          }
					this.rules = res.data.rules;
					this.spinShow = false;
				}).catch(err => {
					this.spinShow = false;
					this.$Message.error(err.msg)
				})
			},
			// 提交表单
			onSubmit(formData) {
				request({
					url: `system/config/${this.currentTab}`,
					method: 'post',
					data: formData
				}).then(res => {
					this.$Message.success(res.msg);
				}).catch(res => {
					this.$Message.error(res.msg);
				});
			}
		}
	}
</script>

<style>
</style>
