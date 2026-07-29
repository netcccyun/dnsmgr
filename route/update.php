<?php
/**
 * 在线更新路由（独立文件）
 * 官方 Release 包只会覆盖 route/app.php，不会删除本文件，从而保证更新入口在升级后仍可用。
 */
use app\middleware\CheckLogin;
use app\middleware\ViewOutput;
use think\facade\Route;

Route::group(function () {
    // 完整匹配，避免 /update 把 /update/check 吃掉
    Route::get('/update/check', 'update/check')->completeMatch(true);
    Route::post('/update/apply', 'update/apply')->completeMatch(true);
    Route::get('/update', 'update/index')->completeMatch(true);
    // 兼容旧地址
    Route::get('/system/updatecheck', 'update/check')->completeMatch(true);
    Route::post('/system/updateapply', 'update/apply')->completeMatch(true);
    Route::get('/system/updateset', 'update/index')->completeMatch(true);
})->middleware(CheckLogin::class)
  ->middleware(ViewOutput::class);
