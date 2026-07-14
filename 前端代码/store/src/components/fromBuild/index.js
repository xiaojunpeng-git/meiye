//注册组件
export default {
    components:{
        inputBuild: () => import('./inputBuild'),
		tabsBuild: () => import('./tabsBuild'),
		radioBuild: () => import('./radioBuild'),
		switchBuild: () => import('./switchBuild'),
    },
}