import Schema from 'async-validator';
import { array } from 'js-md5';
export default {
    props:{
        options:{
            type: Array,
            default(){
                return [];
            },
        },
        //默认值
        value: {
            type: String | Number|Array,
            default:'',
        },
        placeholder: {
            type: String,
            default:'',
        },
        //标题
        title: {
            type: String,
            default:''
        },
        //input类型
        type: {
            type: String,
            default:'text'
        },
        //input后缀
        suffix: {
            type: String,
            default:''
        },
        //input前缀
        prefix: {
            type: String,
            default:''
        },
        //样式
        styleModel: {
            type: String,
            default:''
        },
        //样式名称
        className: {
            type: String,
            default:''
        },
        //字段名称
        field: {
            type: String,
            default:'',
        },
        timerType:{
            type: String,
            default:'timerange',
        },
        timerFormat:{
            type: String,
            default:'HH:mm:ss',
        },
        //说明
        info: {
            type: String,
            default:'',
        },
        //事件
        on: {
            type: Object,
            default() {
                return {};
            }
        },
        validate: {
            type: Object,
            default() {
                return {};
            },
        },
        errorsValidate: {
            type: Array,
            default() {
                return [];
            }
        }
    },
    data() {
        return {
            valueModel: this.value,
            errorMessage:'',  
            exampleImage:{
                'store_terminal_number':'/statics/system/yilianyunPrinter.png',
				'store_config_export_siid': '/statics/system/kuadi100Dump.png'
            },
            exampleSize:{
                'store_terminal_number':364,
                'store_config_export_siid':364
            }
        };
    },
    watch: {
        errorsValidate: {
            handler(n) {
                if (n) {
                    let  error = n.find(item => item.field === this.field);
                    this.errorMessage = error ? error.message : '';
                } else {
                    this.errorMessage = '';
                }
            },
            deep:true
        }
    },
    methods: {
      
        //获取class
        getClassName() {
          let value = ['input-build-' + this.field];
          if (this.errorMessage){
              value.push('ivu-form-item-error');
          }
          let filter = this.validate[this.field] ? this.validate[this.field].filter(item => item.required === true) : [];
          if (filter.length) {
              value.push('ivu-form-item-required')
          }
          return value;
        },
        //事件回调绑定
        changeEvent(name, item) {
            if ('change' === name) {
                this.$emit('changeValue',{field: this.field, value: this.valueModel});
            }
            this.on[name] && this.on[name](item);
            //验证数据
            this.validator(name);
        },
        //数据验证
        validator(name) {
            let filter = this.validate[this.field] ? this.validate[this.field].filter(item=> item.trigger === name) : [];
            if(!filter.length){
                return ;
            }
            const validator = new Schema(this.validate);
            let  source = {[this.field]: this.valueModel};
            validator.validate(source,(errors, fields) => {
              if (errors) {
                  let  error = errors.find(item => item.field === this.field)
                  this.errorMessage = error ? error.message : '';
              } else {
                  this.errorMessage = '';
              }
            })
        },
    }
}