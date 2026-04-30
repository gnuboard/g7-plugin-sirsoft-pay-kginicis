<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Kginicis\Controllers;

use App\Extension\HookManager;
use App\Services\PluginSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Exceptions\PaymentAmountMismatchException;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Plugins\Sirsoft\Pay\Kginicis\Http\Requests\AuthCallbackRequest;
use Plugins\Sirsoft\Pay\Kginicis\Http\Requests\VbankNotifyRequest;
use Plugins\Sirsoft\Pay\Kginicis\Services\KgInicisApiService;

/**
 * KG 이니시스 결제 콜백 컨트롤러
 *
 * KG 이니시스 표준결제창은 2단계 인증 방식입니다:
 *  1단계: 브라우저가 POST 콜백으로 authToken + authUrl 전달 → authCallback()
 *  2단계: 서버가 authUrl로 최종 승인 요청 → KgInicisApiService::authorizePayment()
 */
class PaymentCallbackController
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-kginicis';

    public function __construct(
        private readonly OrderProcessingService $orderService,
        private readonly PluginSettingsService $pluginSettingsService,
        private readonly KgInicisApiService $apiService,
    ) {}

    /**
     * KG 이니시스 결제 승인 콜백
     *
     * POST /plugins/sirsoft-pay-kginicis/payment/callback
     * (CSRF 제외 - 이니시스가 브라우저 통해 POST 전달)
     */
    public function authCallback(AuthCallbackRequest $request): \Illuminate\Http\RedirectResponse
    {
        $validated = $request->validated();

        $resultCode = $validated['resultCode'];
        $moid = $validated['MOID'];
        $totPrice = (int) $validated['TotPrice'];

        Log::info('KG Inicis: callback received', [
            'moid'        => $moid,
            'result_code' => $resultCode,
            'idc_name'    => $validated['idc_name'] ?? null,
            'auth_url'    => $validated['authUrl'] ?? null,
        ]);

        // 결제 실패인 경우: authToken/authUrl 없이 올 수 있으므로 먼저 처리
        if ($resultCode !== '0000') {
            Log::warning('KG Inicis: auth result failed', [
                'moid'        => $moid,
                'result_code' => $resultCode,
                'result_msg'  => $validated['resultMsg'] ?? '',
            ]);

            return redirect($this->resolveFailUrl([
                'error'   => $resultCode,
                'message' => $validated['resultMsg'] ?? '',
                'orderId' => $moid,
            ]));
        }

        // 결제 성공(0000) 이후: authToken, authUrl, idc_name 필수
        $authToken = $validated['authToken'] ?? null;
        $idcName = $validated['idc_name'] ?? null;
        $receivedAuthUrl = $validated['authUrl'] ?? null;
        $receivedNetCancelUrl = $validated['netCancelUrl'] ?? null;

        if (! $authToken || ! $idcName || ! $receivedAuthUrl) {
            Log::error('KG Inicis: missing required fields on success callback', [
                'moid'      => $moid,
                'idc_name'  => $idcName,
                'auth_url'  => $receivedAuthUrl,
                'has_token' => (bool) $authToken,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'missing_fields', 'orderId' => $moid]));
        }

        // idc_name + authUrl 화이트리스트 검증 (PC/모바일 URL 모두 허용, SSRF 방어)
        if (! $this->apiService->isValidIdcAuthUrl($idcName, $receivedAuthUrl)) {
            Log::error('KG Inicis: authUrl not in whitelist (possible SSRF attempt)', [
                'moid'     => $moid,
                'idc_name' => $idcName,
                'received' => $receivedAuthUrl,
            ]);

            return redirect($this->resolveFailUrl(['error' => 'auth_url_invalid', 'orderId' => $moid]));
        }

        // 화이트리스트 검증 통과 → 수신된 URL을 그대로 사용 (PC/모바일 자동 대응)
        $authUrl = $receivedAuthUrl;
        $netCancelUrl = $this->apiService->resolveIdcNetCancelUrl($idcName);

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: order not found', ['moid' => $moid]);

                return redirect($this->resolveFailUrl(['error' => 'order_not_found', 'orderId' => $moid]));
            }

            HookManager::doAction('sirsoft-pay-kginicis.payment.before_authorize', $order, $validated);

            $pgResponse = $this->apiService->authorizePayment($authUrl, $authToken);

            HookManager::doAction('sirsoft-pay-kginicis.payment.after_authorize', $order, $pgResponse);

            $pgResultCode = $pgResponse['resultCode'] ?? '';

            if ($pgResultCode !== '0000') {
                Log::warning('KG Inicis: authorize failed', [
                    'moid' => $moid,
                    'result_code' => $pgResultCode,
                    'result_msg' => $pgResponse['resultMsg'] ?? '',
                ]);

                $this->orderService->failPayment($order, $pgResultCode, $pgResponse['resultMsg'] ?? '');

                return redirect($this->resolveFailUrl([
                    'error' => $pgResultCode,
                    'message' => $pgResponse['resultMsg'] ?? '',
                    'orderId' => $moid,
                ]));
            }

            $tid = $pgResponse['tid'] ?? '';

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'card_approval_number' => $pgResponse['applNum'] ?? null,
                'card_number_masked' => $pgResponse['cardNum'] ?? $pgResponse['vbankNum'] ?? null,
                'card_name' => $pgResponse['cardName'] ?? $pgResponse['vbankName'] ?? null,
                'card_installment_months' => (int) ($pgResponse['cardQuota'] ?? 0),
                'is_interest_free' => false,
                'embedded_pg_provider' => null,
                'receipt_url' => null,
                'payment_meta' => [
                    'result_code' => $pgResultCode,
                    'pay_method' => $pgResponse['payMethod'] ?? null,
                    'auth_date' => $pgResponse['applDate'] ?? null,
                    'vbank_num' => $pgResponse['vbankNum'] ?? null,
                    'vbank_name' => $pgResponse['vbankName'] ?? null,
                    'vbank_exp_date' => $pgResponse['vbankExpDate'] ?? null,
                    'pg_raw_response' => $pgResponse,
                ],
                'payment_device' => $this->detectDevice($request),
            ], $totPrice);

            return redirect($this->resolveSuccessUrl($moid));

        } catch (PaymentAmountMismatchException $e) {
            Log::error('KG Inicis: amount mismatch', [
                'moid' => $moid,
                'expected' => $e->getExpectedAmount(),
                'actual' => $e->getActualAmount(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $authToken);

            return redirect($this->resolveFailUrl(['error' => 'amount_mismatch', 'orderId' => $moid]));

        } catch (\Exception $e) {
            Log::error('KG Inicis: authorize exception', [
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            $this->apiService->sendNetCancel($netCancelUrl, $authToken);

            return redirect($this->resolveFailUrl([
                'error' => 'authorize_failed',
                'message' => $e->getMessage(),
                'orderId' => $moid,
            ]));
        }
    }

    /**
     * 가상계좌 입금 통보 처리
     *
     * POST /plugins/sirsoft-pay-kginicis/payment/vbank-notify
     * (이니시스 서버 → 우리 서버, CSRF 제외)
     */
    public function vbankNotify(VbankNotifyRequest $request): Response
    {
        $validated = $request->validated();

        $tid = $validated['tid'];
        $moid = $validated['MOID'];
        $totPrice = (int) $validated['TotPrice'];
        $resultCode = $validated['resultCode'];

        if ($resultCode !== '0000') {
            Log::warning('KG Inicis: vbank deposit cancelled', ['tid' => $tid, 'moid' => $moid]);

            return response('OK', 200)->header('Content-Type', 'text/plain');
        }

        try {
            $order = $this->orderService->findByOrderNumber($moid);

            if (! $order) {
                Log::error('KG Inicis: vbank notify - order not found', ['moid' => $moid, 'tid' => $tid]);

                return response('FAIL', 200)->header('Content-Type', 'text/plain');
            }

            $this->orderService->completePayment($order, [
                'transaction_id' => $tid,
                'payment_meta' => [
                    'result_code' => $resultCode,
                    'vbank_num' => $validated['vbankNum'] ?? null,
                    'vbank_name' => $validated['vbankName'] ?? null,
                    'vbank_exp_date' => $validated['vbankExpDate'] ?? null,
                    'pg_raw_response' => $validated,
                ],
            ], $totPrice);

            Log::info('KG Inicis: vbank deposit confirmed', ['tid' => $tid, 'moid' => $moid, 'amt' => $totPrice]);

            return response('OK', 200)->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('KG Inicis: vbank notify failed', [
                'tid' => $tid,
                'moid' => $moid,
                'error' => $e->getMessage(),
            ]);

            return response('FAIL', 200)->header('Content-Type', 'text/plain');
        }
    }

    private function resolveSuccessUrl(string $orderId): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $urlTemplate = $settings['redirect_success_url'] ?? '/shop/orders/{orderId}/complete';

        return str_replace('{orderId}', $orderId, $urlTemplate);
    }

    private function resolveFailUrl(array $queryParams = []): string
    {
        $settings = $this->pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $baseUrl = $settings['redirect_fail_url'] ?? '/shop/checkout';

        if (empty($queryParams)) {
            return $baseUrl;
        }

        $query = http_build_query(array_filter($queryParams));
        $separator = str_contains($baseUrl, '?') ? '&' : '?';

        return $baseUrl . $separator . $query;
    }

    private function detectDevice(Request $request): string
    {
        $userAgent = $request->userAgent() ?? '';
        $mobileKeywords = ['Mobile', 'Android', 'iPhone', 'iPad', 'iPod'];

        foreach ($mobileKeywords as $keyword) {
            if (stripos($userAgent, $keyword) !== false) {
                return 'mobile';
            }
        }

        return 'pc';
    }
}
