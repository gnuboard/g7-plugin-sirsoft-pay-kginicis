<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Kginicis\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Plugins\Sirsoft\Pay\Kginicis\Services\KgInicisApiService;

class CbtHashDataController
{
    public function __construct(
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * CBT 해시 데이터 생성
     *
     * POST /api/plugins/sirsoft-pay-kginicis/payment/cbt/hash-data
     * Body: { oid, price, timestamp }
     */
    public function generate(Request $request): JsonResponse
    {
        $oid = (string) $request->input('oid', '');
        $price = (int) $request->input('price', 0);
        $timestamp = (string) $request->input('timestamp', '');

        if ($oid === '' || $price <= 0 || $timestamp === '') {
            return response()->json([
                'success' => false,
                'message' => 'Missing required parameters: oid, price, timestamp',
            ], 422);
        }

        $mid = $this->apiService->getJapanMid();
        $hashData = $this->apiService->generateCbtHashData($mid, $timestamp, $price, $oid);

        return response()->json([
            'success' => true,
            'data' => [
                'hash_data' => $hashData,
            ],
        ]);
    }
}
