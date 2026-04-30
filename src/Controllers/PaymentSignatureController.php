<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Kginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Plugins\Sirsoft\Pay\Kginicis\Http\Requests\SignatureRequest;
use Plugins\Sirsoft\Pay\Kginicis\Services\KgInicisApiService;

class PaymentSignatureController
{
    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * 결제창 서명 생성
     *
     * POST /api/plugins/sirsoft-pay-kginicis/payment/signature
     * Body: { oid, price, timestamp }
     */
    public function generate(SignatureRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $oid = $validated['oid'];
        $price = (int) $validated['price'];
        $timestamp = $validated['timestamp'];

        $signature = $this->apiService->generateSignature($oid, $price, $timestamp);
        $mKey = $this->apiService->getMKey();

        return response()->json([
            'data' => [
                'signature' => $signature,
                'mKey' => $mKey,
            ],
        ]);
    }
}
