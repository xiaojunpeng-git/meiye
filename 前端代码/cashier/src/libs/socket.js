import util from '@/libs/util';
import Setting from '@/setting';
import Vue from 'vue';

let reconneTimer = {};
let reconneCount = {};

class wsSocket {
    constructor(opt) {
        this.vm = new Vue;
        this.ws = null;
        this.opt = opt || {};
        this.networkStatus = true;
        this.reconneMax = 100;
        this.connectLing = false;
        reconneTimer[this.opt.key] = null;
        reconneCount[this.opt.key] = 0;
        this.init(opt);
        this.networkWath();
        this.defaultEvenv();
    }

    defaultEvenv(){
        this.vm.$on('timeout',this.timeoutEvent.bind(this));
    }

    timeoutEvent(){
        this.reconne();
    }

    guid() {
        return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, function(c) {
            var r = Math.random() * 16 | 0,
                v = c == 'x' ? r : (r & 0x3 | 0x8);
            return v.toString(16);
        });
    }

    addHandler(element, type, handler) {
        if (element.addEventListener) {
            element.addEventListener(type, handler, false);
        } else if (element.attachEvent) {
            element.attachEvent("on" + type, handler);
        } else {
            element["on" + type] = handler;
        }
    }

    networkWath(){
        this.addHandler(window,'online',()=>{
            this.networkStatus = true;
            console.log('联网了')
            this.ws.close();
            this.vm.$on('timeout',this.timeoutEvent);
        })
        this.addHandler(window,'offline',()=>{
            this.networkStatus = false;
            this.socketStatus = false;
            this.timer && clearInterval(this.timer);
            this.timer = null;
            this.vm.$off('timeout',this.timeoutEvent);
            console.log('断网了')
        });
    }

    reconne(){
        if (reconneCount[this.opt.key] > this.reconneMax) {
            //重连次数超过限制不再重连
            if (reconneTimer[this.opt.key]) {
                clearInterval(reconneTimer[this.opt.key]);
                reconneTimer[this.opt.key] = null;
            }
            return;
        }
        if (reconneTimer[this.opt.key] || this.socketStatus) {
            return;
        }
        reconneTimer[this.opt.key] = setInterval(() =>{
            //断线连接中发现状态为真就不用再连接
            if (this.socketStatus) {
                return;
            }
            //正在连接中也不需要在连接了
            if (!this.connectLing) {
                console.log('重新连接')
                this.init(this.opt);
                reconneCount[this.opt.key]++;
            }
        },2000)
    }

    onOpen(key = false) {
        //关闭断线重连定时器
        clearInterval(reconneTimer[this.opt.key]);
        reconneTimer[this.opt.key] = null;

        this.connectLing = false;
        this.opt.open && this.opt.open();
        reconneCount[this.opt.key] = 0
        this.socketStatus = true;
        this.ping();
    }

    init(opt) {
        let wsUrl = ''
        let hostUrl = Setting.wsSocketUrl
        if (sessionStorage.getItem("SERVER_TYPE") !== 'nginx') {
            hostUrl = hostUrl + '/ws'
        }
        if (opt.key == 1) {
            let token = util.cookies.get("token")
            if (!token) {
                token = localStorage.getItem('token');
            }
            wsUrl = hostUrl + '?type=cashier' + '&token=' + token;
        }
        if (wsUrl) {
            this.ws = new WebSocket(util.wss(wsUrl));
            this.ws.onopen = this.onOpen.bind(this);
            this.ws.onerror = this.onError.bind(this);
            this.ws.onmessage = this.onMessage.bind(this);
            this.ws.onclose = this.onClose.bind(this);
            this.connectLing = true;
        }

    }

    ping() {
        var that = this;
        this.timer = setInterval(() => {
            that.send({ type: 'ping' });
        }, 10000);
    }

    send(data) {
        if(!this.socketStatus || this.ws.readyState === 0 || !this.networkStatus){
            this.reconne();
        }
        return new Promise((resolve, reject) => {
            try {
                this.ws.send(JSON.stringify(data));
                resolve({ status: true });
            } catch(e) {
                reject({ status: false,socketStatus: this.socketStatus,networkStatus:this.networkStatus})
            }
        });
    }

    onMessage(res) {
        let datas = JSON.parse(res.data);
        let type = datas.type;
        let data = datas.data;
        this.opt.message && this.opt.message(res);
        this.vm.$emit(type,data);
    }

    onClose() {
        this.connectLing = false;
        this.timer && clearInterval(this.timer);
        this.timer = null;
        this.opt.close && this.opt.close();
        this.socketStatus = false;
        this.reconne();
    }

    onError(e) {
        this.connectLing = false;
        this.timer && clearInterval(this.timer);
        this.timer = null;
        this.opt.error && this.opt.error(e);
        this.socketStatus = false;
        this.reconne();
    }

    $once(...args) {
        this.vm.$once(...args);
    }
    $on(...args) {
        this.vm.$on(...args);
    }
    $off(...args) {
        this.vm.$off(...args);
    }
}
export default wsSocket

