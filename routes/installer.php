<?php

use App\Http\Controllers\InstallController;
use App\Http\Middleware\EnsureInstalling;
use App\Http\Middleware\HandleInstallerRequests;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware([
    EncryptCookies::class,
    AddQueuedCookiesToResponse::class,
    StartSession::class,
    ShareErrorsFromSession::class,
    PreventRequestForgery::class,
    SubstituteBindings::class,
    HandleInstallerRequests::class,
    EnsureInstalling::class,
])->prefix('install')->name('install.')->group(function () {
    Route::get('/', [InstallController::class, 'show'])->name('show');
    Route::post('code', [InstallController::class, 'code'])->name('code');

    Route::middleware(EnsureInstalling::class.':code')->group(function () {
        Route::post('check', [InstallController::class, 'check'])->name('check');
        Route::post('database/test', [InstallController::class, 'testDatabase'])->name('database.test');
        Route::post('database', [InstallController::class, 'saveDatabase'])->name('database');
        Route::post('database/migrate', [InstallController::class, 'migrate'])->name('database.migrate');
        Route::post('address/check', [InstallController::class, 'checkAddress'])->name('address.check');
        Route::post('address', [InstallController::class, 'saveAddress'])->name('address');
        Route::post('mail/test', [InstallController::class, 'testMail'])->name('mail.test');
        Route::post('mail', [InstallController::class, 'saveMail'])->name('mail');
        Route::post('mail/skip', [InstallController::class, 'skipMail'])->name('mail.skip');
        Route::post('owner', [InstallController::class, 'saveOwner'])->name('owner');
        Route::post('finish', [InstallController::class, 'finish'])->name('finish');
    });
});
