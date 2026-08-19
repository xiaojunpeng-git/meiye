/**
 * 系统内置方法集，正常情况下您不应该修改或移除此文件
 * */

import { cloneDeep } from 'lodash';

/**
 * @description 根据当前路由，找打顶部菜单名称
 * @param {String} currentPath 当前路径
 * @param {Array} menuList 所有路径
 * */
function getHeaderName(to, menuList) {
  const allMenus = [];
  menuList.forEach(menu => {
    const headerName = menu.header || '';
    const menus = transferMenu(menu, headerName);
    allMenus.push({
      path: menu.path,
      header: headerName
    });
    menus.forEach(item => allMenus.push(item));
  });
  const currentMenu = allMenus.find(item => {
    return isMenuPathMatch(to, item.path);
  });
  return currentMenu ? currentMenu.header : null;
}

function getPath(to, path) {
  const params = [];
  const query = [];
  Object.keys(to.params).forEach((item) => {
    params.push(to.params[item]);
  });
  Object.keys(to.query).forEach((item) => {
    query.push(item + '=' + to.query[item]);
  });
  return path + (params.length ? '/' + params.join('/') : '') + (query.length ? '?' + query.join('&') : '');
}

function transferMenu(menu, headerName) {
  if (menu.children && menu.children.length) {
    return menu.children.reduce((all, item) => {
      all.push({
        path: item.path,
        header: headerName
      });
      const foundChildren = transferMenu(item, headerName);
      return all.concat(foundChildren);
    }, []);
  } else {
    return [menu];
  }
}

function splitMenuPath(menuPath) {
  const value = String(menuPath || '');
  const index = value.indexOf('?');
  return index === -1
    ? { pathname: value, query: new URLSearchParams() }
    : { pathname: value.slice(0, index), query: new URLSearchParams(value.slice(index + 1)) };
}

/** Match both ordinary routes and duplicate-menu entry markers. */
function isMenuPathMatch(to, menuPath) {
  const { pathname, query } = splitMenuPath(menuPath);
  const normalize = (path) => (path || '').replace(/\/$/, '');
  const currentEntry = String((to && to.query && to.query.menu_entry) || '');
  const targetEntry = query.get('menu_entry') || '';
  const currentPath = normalize(to && to.path);
  const targetPath = normalize(pathname);

  if (targetPath !== currentPath && currentPath !== normalize(getPath(to, pathname))) return false;
  if (currentEntry || targetEntry) return currentEntry === targetEntry;

  const targetTab = query.get('tab') || '';
  if (targetTab) return String((to && to.query && to.query.tab) || '') === targetTab;
  if (['stores', 'people'].includes(String((to && to.query && to.query.tab) || ''))) return false;
  return true;
}

export { getHeaderName, getPath, isMenuPathMatch };

/**
 * @description 根据当前路由，找打顶部菜单名称
 * @param {String} currentPath 当前路径
 * @param {Array} menuList 所有路径
 * */
function getHeaderSider(menuList) {
  return menuList.filter(item => item.is_header === 1);
}

export { getHeaderSider };

/**
 * @description 根据当前顶栏菜单 name，找到对应的二级菜单
 * @param {Array} menuList 所有的二级菜单
 * @param {String} headerName 当前顶栏菜单的 name
 * */
function getMenuSider(menuList, headerName = '') {
  if (headerName) {
    return menuList.filter(item => item.header === headerName);
  } else {
    return menuList;
  }
}

export { getMenuSider };

/**
 * @description 根据当前路由，找到其所有父菜单 path，作为展开侧边栏 open-names 依据
 * @param {String} currentPath 当前路径
 * @param {Array} menuList 所有路径
 * */
// function getSiderSubmenu (currentPath, menuList) {
//     const allMenus = [];
//     menuList.forEach(menu => {
//         const menus = transferSubMenu(menu, []);
//         allMenus.push({
//             path: menu.path,
//             openNames: []
//         });
//         menus.forEach(item => allMenus.push(item));
//     });
//     const currentMenu = allMenus.find(item => item.path === currentPath);
//     return currentMenu ? currentMenu.openNames : [];
// }

function getSiderSubmenu(to, menuList) {
  const allMenus = [];
  menuList.forEach(menu => {
    const menus = transferSubMenu(menu, []);
    allMenus.push({
      path: menu.path,
      openNames: []
    });
    menus.forEach(item => allMenus.push(item));
  });
  const currentMenu = allMenus.find(item => {
    if (item.openNames.length) return isMenuPathMatch(to, item.path);
  });
  return currentMenu ? currentMenu.openNames : [];
}

function transferSubMenu(menu, openNames) {
  if (menu.children && menu.children.length) {
    const itemOpenNames = openNames.concat([menu.path]);
    return menu.children.reduce((all, item) => {
      all.push({
        path: item.path,
        openNames: itemOpenNames
      });
      const foundChildren = transferSubMenu(item, itemOpenNames);
      return all.concat(foundChildren);
    }, []);
  } else {
    return [menu].map(item => {
      return {
        path: item.path,
        openNames: openNames
      };
    });
  }
}

export { getSiderSubmenu };

/**
 * @description 递归获取所有子菜单
 * */
function getAllSiderMenu(menuList) {
  const allMenus = [];

  menuList.forEach(menu => {
    if (menu.children && menu.children.length) {
      const menus = getMenuChildren(menu);
      menus.forEach(item => allMenus.push(item));
    } else {
      allMenus.push(menu);
    }
  });

  return allMenus;
}

function getMenuChildren(menu) {
  if (menu.children && menu.children.length) {
    return menu.children.reduce((all, item) => {
      const foundChildren = getMenuChildren(item);
      return all.concat(foundChildren);
    }, []);
  } else {
    return [menu];
  }
}

export { getAllSiderMenu };

/**
 * @description 将菜单转为平级
 * */
function flattenSiderMenu(menuList, newList) {
  menuList.forEach(menu => {
    const newMenu = {};
    for (const i in menu) {
      if (i !== 'children') newMenu[i] = cloneDeep(menu[i]);
    }
    newList.push(newMenu);
    menu.children && flattenSiderMenu(menu.children, newList);
  });
  return newList;
}

export { flattenSiderMenu };

/**
 * @description 判断列表1中是否包含了列表2中的某一项
 * 因为用户权限 access 为数组，includes 方法无法直接得出结论
 * */
function includeArray(list1, list2) {
  let status = false;
  if (list1 === true) {
    return true;
  } else {
    if (typeof list2 !== 'object') {
      return false;
    }
    list2.forEach(item => {
      if (list1.includes(item)) status = true;
    });
    return status;
  }
}
export { includeArray };
