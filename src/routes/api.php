<?php

use Illuminate\Support\Facades\Route;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\CbtHashDataController;
use Plugins\Sirsoft\Pay\Kginicis\Controllers\PaymentSignatureController;

/*
|--------------------------------------------------------------------------
| KG Inicis Plugin API Routes
|--------------------------------------------------------------------------
|
| 프리픽스: /api/plugins/sirsoft-pay-kginicis (PluginRouteServiceProvider 자동 적용)
| 미들웨어: api (PluginRouteServiceProvider 자동 적용)
|
*/

// 결제창 서명 생성 — 인증 불필요, 프론트엔드에서 직접 호출
Route::post('/payment/signature', [PaymentSignatureController::class, 'generate'])
    ->name('payment.signature');

// CBT 해시 데이터 생성 — 인증 불필요, 프론트엔드에서 직접 호출
Route::post('/payment/cbt/hash-data', [CbtHashDataController::class, 'generate'])
    ->name('payment.cbt.hash-data');

Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'admin'])->group(function () {
    // 가상계좌 입금통보 URL 조회 (관리자 설정 페이지 표시용)
    Route::get('/vbank-notify-url', function () {
        return response()->json([
            'success' => true,
            'data' => [
                'url' => url('/plugins/sirsoft-pay-kginicis/payment/vbank-notify'),
            ],
        ]);
    })->name('vbank.notify.url');
});
