<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Kginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Pay\Kginicis\Http\Requests\MobileSignatureRequest;
use Plugins\Sirsoft\Pay\Kginicis\Services\KgInicisApiService;

class MobileSignatureController
{
    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * 모바일 결제 위변조 방지 해시(P_CHKFAKE) 생성
     *
     * POST /api/plugins/sirsoft-pay-kginicis/payment/mobile/signature
     * Body: { oid, price, timestamp }
     */
    public function generate(MobileSignatureRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $chkfake = $this->apiService->generateMobileChkfake(
            $validated['oid'],
            (int) $validated['price'],
            $validated['timestamp'],
        );

        return response()->json([
            'data' => [
                'chkfake' => $chkfake,
                'mobile_payment_url' => $this->apiService->getMobilePaymentUrl(),
            ],
        ]);
    }
}
