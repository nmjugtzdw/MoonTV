<?php
use think\facade\Route;

// 前端页面路由
Route::get('/', 'Index/index');
Route::get('/list/:type', 'Index/list');
Route::get('/search', 'Index/search');
Route::get('/history', 'Index/history');
Route::get('/detail/:id', 'Index/detail');
Route::get('/play/:id', 'Index/play');
Route::get('/vip', 'Index/vip');
Route::get('/user', 'Index/user');
Route::get('/orders', 'Index/orders'); // 用户订单页面
Route::get('/login', 'Index/login');
Route::get('/register', 'Index/register');

// API 路由（全局 CORS 中间件）
Route::group('api', function () {
    // 鉴权
    Route::post('login', 'api.Auth/login');
    Route::post('register', 'api.Auth/register');
    Route::get('user/info', 'api.Auth/info')->middleware(\app\middleware\AuthCheck::class);
    Route::post('user/password', 'api.Auth/updatePassword')->middleware(\app\middleware\AuthCheck::class);

    // 搜索与详情
    Route::get('search', 'api.Search/index');
    Route::get('search/hot', 'api.SearchHot/index');
    Route::get('search/suggestions', 'api.SearchHot/suggestions');
    Route::get('detail', 'api.Detail/index');

    // 用户数据
    Route::get('orders', 'api.UserData/getOrders'); // 用户订单列表API

    Route::group('playrecord', function () {
        Route::get('/', 'api.UserData/getPlayRecords');
        Route::post('/', 'api.UserData/savePlayRecord');
        Route::delete('/', 'api.UserData/deletePlayRecord');
    })->middleware(\app\middleware\AuthCheck::class);

    Route::group('favorite', function () {
        Route::get('/', 'api.UserData/getFavorites');
        Route::post('/', 'api.UserData/saveFavorite');
        Route::delete('/', 'api.UserData/deleteFavorite');
        Route::get('/check', 'api.UserData/isFavorited');
    })->middleware(\app\middleware\AuthCheck::class);

    Route::group('searchhistory', function () {
        Route::get('/', 'api.UserData/getSearchHistory');
        Route::post('/', 'api.UserData/addSearchHistory');
        Route::delete('/', 'api.UserData/deleteSearchHistory');
    })->middleware(\app\middleware\AuthCheck::class);

    // VIP与支付
    Route::get('vip/packages', 'api.Vip/packages');
    Route::post('vip/order/create', 'api.Vip/createOrder');
    Route::post('vip/redeem', 'api.Vip/redeem');
    Route::any('vip/notify', 'api.Vip/notify'); // 支付回调

    // 配置接口
    Route::get('config/sources', 'api.Config/sources');
    Route::get('config/get', 'api.Admin/getConfig'); // 前端公用配置获取

    // 弹幕 API
    Route::get('danmaku', 'api.Danmaku/index');
    Route::post('danmaku/send', 'api.Danmaku/send');
    Route::post('danmaku/save', 'api.Danmaku/send'); // 兼容旧路由

    // 推荐接口（新版，支持分页和分类，自动根据配置选择豆瓣或资源站）
    Route::get('recommend', 'api.Home/index');
    Route::get('douban/recommend', 'api.Home/index'); // 兼容旧路由
    
    // 豆瓣推荐接口（保留兼容）
    Route::get('douban/recommends', 'api.Douban/recommends');
    Route::get('douban/hot/movies', 'api.Douban/hotMovies');
    Route::get('douban/hot/tv', 'api.Douban/hotTvShows');
    Route::get('douban/hot/variety', 'api.Douban/hotVarietyShows');
    Route::get('douban/hot/anime', 'api.Douban/hotAnime');
    Route::get('douban/hot/backup', 'api.Douban/hotFromBackup'); // 备选源接口

    // TVBox
    Route::get('tvbox/config', 'api.Tvbox/config');
    Route::get('tvbox/categories', 'api.Tvbox/categories');

    // 图片代理和缓存
    Route::get('image-proxy', 'api.ImageProxy/index');
    Route::post('image-proxy/clear', 'api.ImageProxy/clearHomepageCache');

    // 管理员 API
    Route::group('admin', function() {
        Route::post('login', 'api.Admin/login');
        Route::post('password', 'api.Admin/updatePassword')->middleware(\app\middleware\AdminCheck::class); // 管理员修改密码
        Route::get('dashboard', 'api.Admin/dashboard')->middleware(\app\middleware\AdminCheck::class);
        Route::get('users', 'api.Admin/users')->middleware(\app\middleware\AdminCheck::class);
        
        // VIP套餐管理
        Route::get('vip/packages', 'api.Admin/vipPackages')->middleware(\app\middleware\AdminCheck::class);
        Route::post('vip/package/save', 'api.Admin/saveVipPackage')->middleware(\app\middleware\AdminCheck::class);
        Route::post('vip/package/delete', 'api.Admin/deleteVipPackage')->middleware(\app\middleware\AdminCheck::class);

        // 系统设置
        Route::get('system/config', 'api.Admin/systemConfig')->middleware(\app\middleware\AdminCheck::class);
        Route::post('system/config/save', 'api.Admin/saveSystemConfig')->middleware(\app\middleware\AdminCheck::class);
        Route::get('config/get', 'api.Admin/getConfig')->middleware(\app\middleware\AdminCheck::class);
        Route::post('config/set', 'api.Admin/setConfig')->middleware(\app\middleware\AdminCheck::class);
        Route::post('users/update_stats', 'api.Admin/updateUserStats')->middleware(\app\middleware\AdminCheck::class);

        // 卡密管理
        Route::get('cards', 'api.Admin/cards')->middleware(\app\middleware\AdminCheck::class);
        Route::post('cards/generate', 'api.Admin/generateCards')->middleware(\app\middleware\AdminCheck::class);
        Route::post('cards/delete', 'api.Admin/deleteCard')->middleware(\app\middleware\AdminCheck::class);
        Route::get('cards/export', 'api.Admin/exportCards')->middleware(\app\middleware\AdminCheck::class);
        
        // 订单管理
        Route::get('orders', 'api.Admin/orders')->middleware(\app\middleware\AdminCheck::class);
        
        // 弹幕管理
        Route::get('danmaku/list', 'api.Admin/danmakuList')->middleware(\app\middleware\AdminCheck::class);
        Route::get('danmaku/stats', 'api.Admin/danmakuStats')->middleware(\app\middleware\AdminCheck::class);
        Route::post('danmaku/delete', 'api.Admin/deleteDanmaku')->middleware(\app\middleware\AdminCheck::class);
        Route::post('danmaku/batch-delete', 'api.Admin/batchDeleteDanmaku')->middleware(\app\middleware\AdminCheck::class);
        Route::post('danmaku/toggle', 'api.Admin/toggleDanmakuStatus')->middleware(\app\middleware\AdminCheck::class);
    });
})->middleware(\app\middleware\Cors::class);

// 后台页面路由
Route::group('admin', function() {
    Route::get('/', 'Admin/index')->middleware(\app\middleware\AdminCheck::class);
    Route::get('login', 'Admin/login');
    Route::get('users', 'Admin/users')->middleware(\app\middleware\AdminCheck::class);
    Route::get('vip', 'Admin/vip')->middleware(\app\middleware\AdminCheck::class);
    Route::get('cards', 'Admin/cards')->middleware(\app\middleware\AdminCheck::class);
    Route::get('orders', 'Admin/orders')->middleware(\app\middleware\AdminCheck::class);
    Route::get('system', 'Admin/system')->middleware(\app\middleware\AdminCheck::class);
    Route::get('danmaku', 'Admin/danmaku')->middleware(\app\middleware\AdminCheck::class);
});
