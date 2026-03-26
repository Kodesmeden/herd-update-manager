<?php

use App\Http\Controllers\AppInfoController;
use App\Http\Controllers\DiagnosticsController;
use App\Http\Controllers\GitController;
use App\Http\Controllers\InstallationController;
use Illuminate\Support\Facades\Route;

Route::get('/', [InstallationController::class, 'index'])->name('home');
Route::patch('/installations/{installation}/dismiss', [InstallationController::class, 'dismiss'])->name('installations.dismiss');
Route::patch('/installations/{installation}/hide', [InstallationController::class, 'hide'])->name('installations.hide');
Route::patch('/installations/{installation}/unhide', [InstallationController::class, 'unhide'])->name('installations.unhide');
Route::post('/installations/{installation}/update', [InstallationController::class, 'update'])->name('installations.update');
Route::post('/installations/{installation}/push', [InstallationController::class, 'push'])->name('installations.push');
Route::post('/installations/update-all', [InstallationController::class, 'updateAll'])->name('installations.update-all');
Route::post('/installations/push-all', [InstallationController::class, 'pushAll'])->name('installations.push-all');
Route::post('/installations/fetch-all', [InstallationController::class, 'fetchAll'])->name('installations.fetch-all');

Route::get('/installations/{installation}/git-info', [GitController::class, 'info'])->name('installations.git-info');
Route::get('/installations/{installation}/git/branches', [GitController::class, 'branches'])->name('installations.git.branches');
Route::post('/installations/{installation}/git/switch', [GitController::class, 'switchBranch'])->name('installations.git.switch');
Route::post('/installations/{installation}/git/branch', [GitController::class, 'createBranch'])->name('installations.git.branch');
Route::post('/installations/{installation}/git/pr', [GitController::class, 'createPr'])->name('installations.git.pr');
Route::post('/installations/{installation}/git/merge', [GitController::class, 'mergePr'])->name('installations.git.merge');

Route::get('/installations/{installation}/app-info', [AppInfoController::class, 'show'])->name('installations.app-info');

Route::get('/diagnostics/{check}', [DiagnosticsController::class, 'run'])->name('diagnostics.run');
