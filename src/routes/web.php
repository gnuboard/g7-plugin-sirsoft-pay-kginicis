<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\CbtCallbackController;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\PaymentCallbackController;

Route::get('/payment/cbt/callback', [CbtCallbackController::class, 'handle'])
    ->name('payment.cbt.callback');

Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->group(function () {
    Route::post('/payment/callback', [PaymentCallbackController::class, 'authCallback'])
        ->name('payment.callback');

    Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
        ->name('payment.vbank-notify');
});
