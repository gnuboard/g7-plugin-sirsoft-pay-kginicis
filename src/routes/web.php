<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\CbtCallbackController;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\CbtHashDataController;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\PaymentCallbackController;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\PaymentSignatureController;

Route::get('/payment/signature', [PaymentSignatureController::class, 'generate'])
    ->name('payment.signature');

Route::get('/payment/cbt/hash-data', [CbtHashDataController::class, 'generate'])
    ->name('payment.cbt.hash-data');

Route::get('/payment/cbt/callback', [CbtCallbackController::class, 'handle'])
    ->name('payment.cbt.callback');

Route::withoutMiddleware([\App\Http\Middleware\VerifyCsrfToken::class])->group(function () {
    Route::post('/payment/callback', [PaymentCallbackController::class, 'authCallback'])
        ->name('payment.callback');

    Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
        ->name('payment.vbank-notify');
});
