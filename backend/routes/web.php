<?php

use App\Http\Controllers\Auth\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// Single sign on (Google, Microsoft/Outlook). Providers resolve to 404
// unless enabled and configured.
Route::get('/auth/{provider}/redirect', [SsoController::class, 'redirect'])
    ->whereIn('provider', \App\Services\Auth\SsoManager::PROVIDERS)
    ->name('sso.redirect');

Route::get('/auth/{provider}/callback', [SsoController::class, 'callback'])
    ->whereIn('provider', \App\Services\Auth\SsoManager::PROVIDERS)
    ->name('sso.callback');
