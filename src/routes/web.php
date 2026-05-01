<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\CbtCallbackController;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\EscrowNotifyController;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\MobileCallbackController;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\PaymentCallbackController;

Route::get('/payment/cbt/callback', [CbtCallbackController::class, 'handle'])
    ->name('payment.cbt.callback');

Route::withoutMiddleware([\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])->group(function () {
    Route::post('/payment/callback', [PaymentCallbackController::class, 'authCallback'])
        ->name('payment.callback');

    Route::post('/payment/vbank-notify', [PaymentCallbackController::class, 'vbankNotify'])
        ->name('payment.vbank-notify');

    Route::post('/payment/mobile/vbank-notify', [PaymentCallbackController::class, 'mobileVbankNotify'])
        ->name('payment.mobile.vbank-notify');

    Route::post('/payment/escrow-notify', [EscrowNotifyController::class, 'handle'])
        ->name('payment.escrow-notify');

    // 모바일: KG 이니시스가 인증 후 GET 리다이렉트로 P_NEXT_URL 호출
    Route::get('/payment/mobile/callback', [MobileCallbackController::class, 'handle'])
        ->name('payment.mobile.callback');
});
