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

Route::get('/verifycode', 'auth/verifycode')->middleware(SessionInit::class)
->middleware(ViewOutput::class);
Route::any('/login', 'auth/login')->middleware(SessionInit::class)
->middleware(ViewOutput::class);
Route::get('/logout', 'auth/logout');
Route::any('/quicklogin', 'auth/quicklogin');
Route::any('/dmtask/status', 'dmonitor/status');
Route::any('/optimizeip/status', 'optimizeip/status');

Route::group(function () {
    Route::any('/', 'index/index');
    Route::post('/changeskin', 'index/changeskin');
    Route::get('/cleancache', 'index/cleancache');
    Route::any('/setpwd', 'index/setpwd');
    Route::get('/test', 'index/test');

    Route::post('/user/data', 'user/user_data');
    Route::post('/user/op', 'user/user_op');
    Route::get('/user', 'user/user');
    
    Route::post('/log/data', 'user/log_data');
    Route::get('/log', 'user/log');

    Route::post('/account/data', 'domain/account_data');
    Route::post('/account/op', 'domain/account_op');
    Route::get('/account', 'domain/account');

    Route::post('/domain/data', 'domain/domain_data');
    Route::post('/domain/op', 'domain/domain_op');
    Route::post('/domain/list', 'domain/domain_list');
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
    Route::any('/record/log/:id', 'domain/record_log');
    Route::post('/record/list', 'domain/record_list');
    Route::get('/record/:id', 'domain/record');

    Route::get('/dmonitor/overview', 'dmonitor/overview');
    Route::post('/dmonitor/task/data', 'dmonitor/task_data');
    Route::post('/dmonitor/task/log/data/:id', 'dmonitor/tasklog_data');
    Route::get('/dmonitor/task/info/:id', 'dmonitor/taskinfo');
    Route::any('/dmonitor/task/:action', 'dmonitor/taskform');
    Route::get('/dmonitor/task', 'dmonitor/task');
    Route::any('/dmonitor/noticeset', 'dmonitor/noticeset');
    Route::any('/dmonitor/proxyset', 'dmonitor/proxyset');
    Route::get('/dmonitor/mailtest', 'dmonitor/mailtest');
    Route::get('/dmonitor/tgbottest', 'dmonitor/tgbottest');
    Route::post('/dmonitor/proxytest', 'dmonitor/proxytest');
    Route::post('/dmonitor/clean', 'dmonitor/clean');

    Route::any('/optimizeip/opipset', 'optimizeip/opipset');
    Route::post('/optimizeip/queryapi', 'optimizeip/queryapi');
    Route::post('/optimizeip/opiplist/data', 'optimizeip/opiplist_data');
    Route::get('/optimizeip/opiplist', 'optimizeip/opiplist');
    Route::any('/optimizeip/opipform/:action', 'optimizeip/opipform');

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

})->middleware(AuthApi::class);

Route::miss(function() {
    return response('404 Not Found')->code(404);
});
