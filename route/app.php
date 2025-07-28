<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
use app\middleware\AuthApi;
use app\middleware\CheckLogin;
use app\middleware\ViewOutput;
use think\facade\Route;
use think\middleware\SessionInit;

Route::pattern([
    'id'   => '\d+',
]);

Route::any('/install', 'install/index')
->middleware(ViewOutput::class);

Route::any('/login', 'auth/login')->middleware(SessionInit::class)
->middleware(ViewOutput::class);
Route::get('/verifycode', 'auth/verifycode')->middleware(SessionInit::class);
Route::post('/auth/totp', 'auth/totp')->middleware(SessionInit::class);
Route::get('/logout', 'auth/logout');
Route::any('/quicklogin', 'auth/quicklogin');
Route::any('/dmtask/status', 'dmonitor/status');
Route::any('/optimizeip/status', 'optimizeip/status');
Route::get('/cron', 'system/cron');

Route::group(function () {
    Route::any('/', 'index/index');
    Route::post('/changeskin', 'index/changeskin');
    Route::get('/cleancache', 'index/cleancache');
    Route::any('/setpwd', 'index/setpwd');
    Route::any('/totp/:action', 'index/totp');
    Route::get('/test', 'index/test');

    Route::post('/user/data', 'user/user_data');
    Route::post('/user/op', 'user/user_op');
    Route::get('/user', 'user/user');
    
    Route::post('/log/data', 'user/log_data');
    Route::get('/log', 'user/log');

    Route::post('/account/data', 'domain/account_data');
    Route::post('/account/op', 'domain/account_op');
    Route::get('/account', 'domain/account');

    Route::any('/domain/expirenotice', 'domain/expire_notice');
    Route::post('/domain/updatedate', 'domain/update_date');
    Route::post('/domain/data', 'domain/domain_data');
    Route::post('/domain/op', 'domain/domain_op');
    Route::post('/domain/list', 'domain/domain_list');
    Route::get('/domain/add', 'domain/domain_add');
    Route::get('/domain', 'domain/domain');

    Route::post('/record/data/:id', 'domain/record_data');
    Route::post('/record/add/:id', 'domain/record_add');
    Route::post('/record/update/:id', 'domain/record_update');
    Route::post('/record/delete/:id', 'domain/record_delete');
    Route::post('/record/status/:id', 'domain/record_status');
    Route::post('/record/remark/:id', 'domain/record_remark');
    Route::post('/record/batch/:id', 'domain/record_batch');
    Route::post('/record/batchedit/:id', 'domain/record_batch_edit');
    Route::any('/record/batchadd/:id', 'domain/record_batch_add');
    Route::get('/record/batchadd', 'domain/record_batch_add2');
    Route::any('/record/batchedit', 'domain/record_batch_edit2');
    Route::any('/record/log/:id', 'domain/record_log');
    Route::post('/record/list', 'domain/record_list');
    Route::post('/record/weight/data/:id', 'domain/weight_data');
    Route::any('/record/weight/:id', 'domain/weight');
    Route::get('/record/:id', 'domain/record');

    Route::get('/dmonitor/overview', 'dmonitor/overview');
    Route::post('/dmonitor/task/data', 'dmonitor/task_data');
    Route::post('/dmonitor/task/log/data/:id', 'dmonitor/tasklog_data');
    Route::get('/dmonitor/task/info/:id', 'dmonitor/taskinfo');
    Route::any('/dmonitor/task/:action', 'dmonitor/taskform');
    Route::get('/dmonitor/task', 'dmonitor/task');
    Route::post('/dmonitor/clean', 'dmonitor/clean');

    Route::any('/optimizeip/opipset', 'optimizeip/opipset');
    Route::post('/optimizeip/queryapi', 'optimizeip/queryapi');
    Route::post('/optimizeip/opiplist/data', 'optimizeip/opiplist_data');
    Route::get('/optimizeip/opiplist', 'optimizeip/opiplist');
    Route::any('/optimizeip/opipform/:action', 'optimizeip/opipform');

    Route::get('/cert/certaccount', 'cert/certaccount');
    Route::get('/cert/deployaccount', 'cert/deployaccount');
    Route::post('/cert/account/data', 'cert/account_data');
    Route::post('/cert/account/:action', 'cert/account_op');
    Route::get('/cert/account/:action', 'cert/account_form');

    Route::get('/cert/certorder', 'cert/certorder');
    Route::post('/cert/order/data', 'cert/order_data');
    Route::post('/cert/order/process', 'cert/order_process');
    Route::post('/cert/order/:action', 'cert/order_op');
    Route::get('/cert/order/:action', 'cert/order_form');

    Route::get('/cert/deploytask', 'cert/deploytask');
    Route::post('/cert/deploy/data', 'cert/deploy_data');
    Route::post('/cert/deploy/process', 'cert/deploy_process');
    Route::post('/cert/deploy/:action', 'cert/deploy_op');
    Route::get('/cert/deploy/:action', 'cert/deploy_form');

    Route::get('/cert/cname', 'cert/cname');
    Route::post('/cert/cname/data', 'cert/cname_data');
    Route::post('/cert/cname/:action', 'cert/cname_op');
    
    Route::get('/cert/certset', 'cert/certset');

    Route::get('/system/loginset', 'system/loginset');
    Route::get('/system/noticeset', 'system/noticeset');
    Route::get('/system/proxyset', 'system/proxyset');
    Route::post('/system/set', 'system/set');
    Route::get('/system/mailtest', 'system/mailtest');
    Route::get('/system/tgbottest', 'system/tgbottest');
    Route::get('/system/webhooktest', 'system/webhooktest');
    Route::post('/system/proxytest', 'system/proxytest');
    Route::get('/system/cronset', 'system/cronset');

})->middleware(CheckLogin::class)
->middleware(ViewOutput::class);

Route::group('api', function () {
    Route::post('/domain/:id', 'domain/domain_info');
    Route::post('/domain', 'domain/domain_data');
    
    Route::post('/record/data/:id', 'domain/record_data');
    Route::post('/record/add/:id', 'domain/record_add');
    Route::post('/record/update/:id', 'domain/record_update');
    Route::post('/record/delete/:id', 'domain/record_delete');
    Route::post('/record/status/:id', 'domain/record_status');
    Route::post('/record/remark/:id', 'domain/record_remark');
    Route::post('/record/batch/:id', 'domain/record_batch');

    Route::post('/cert/order', 'cert/order_info');

})->middleware(AuthApi::class);

Route::miss(function() {
    return response('404 Not Found')->code(404);
});
