<?php

use Illuminate\Support\Facades\Route;
use RustixDev\Domains\Http\Controllers\DomainController;

// Reading needs ext.domains.read or ext.domains.manage; everything else needs manage.
Route::get('/domains', [DomainController::class, 'index']);
Route::post('/domains', [DomainController::class, 'store']);
Route::put('/domains/{domain}', [DomainController::class, 'update'])->whereNumber('domain');
Route::delete('/domains/{domain}', [DomainController::class, 'destroy'])->whereNumber('domain');
Route::post('/domains/{domain}/check', [DomainController::class, 'check'])->whereNumber('domain');
Route::post('/domains/{domain}/certificate', [DomainController::class, 'certificate'])->whereNumber('domain');
