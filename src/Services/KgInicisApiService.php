<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Kginicis\Services;

use App\Services\PluginSettingsService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class KgInicisApiService
{
    private const PLUGIN_IDENTIFIER = 'sirsoft-pay-kginicis';

    private const LIVE_MID_PREFIX = 'SIR';

    private const JS_URL_TEST = 'https://stgstdpay.inicis.com/stdjs/INIStdPay.js';

    private const JS_URL_LIVE = 'https://stdpay.inicis.com/stdjs/INIStdPay.js';

    private const API_URL_TEST = 'https://stginiapi.inicis.com/api/v1/refund';

    private const API_URL_LIVE = 'https://iniapi.inicis.com/api/v1/refund';

    /**
     * idc_name → PC 서버 승인 URL 화이트리스트 (SSRF 방어)
     * 출처: 이니시스 PC 일반결제 샘플 properties.php
     */
    private const IDC_AUTH_URLS = [
        'fc'  => 'https://fcstdpay.inicis.com/api/payAuth',
        'ks'  => 'https://ksstdpay.inicis.com/api/payAuth',
        'stg' => 'https://stgstdpay.inicis.com/api/payAuth',
    ];

    /**
     * idc_name → 모바일 서버 승인 URL 화이트리스트
     * 출처: 이니시스 모바일 결제 메뉴얼 IDC센터코드 표
     */
    private const IDC_MOBILE_AUTH_URLS = [
        'fc'  => 'https://fcmobile.inicis.com/smart/payReq.ini',
        'ks'  => 'https://ksmobile.inicis.com/smart/payReq.ini',
        'stg' => 'https://stgmobile.inicis.com/smart/payReq.ini',
    ];

    /** idc_name → PC 망취소 URL 화이트리스트 */
    private const IDC_NET_CANCEL_URLS = [
        'fc'  => 'https://fcstdpay.inicis.com/api/netCancel',
        'ks'  => 'https://ksstdpay.inicis.com/api/netCancel',
        'stg' => 'https://stgstdpay.inicis.com/api/netCancel',
    ];

    private const CBT_AUTH_URL_TEST = 'https://devcbt.inicis.com/cbtauth';

    private const CBT_AUTH_URL_LIVE = 'https://cbt.inicis.com/cbtauth';

    private const CBT_APPROVE_URL_TEST = 'https://devcbt.inicis.com/cbtapprove';

    private const CBT_APPROVE_URL_LIVE = 'https://cbt.inicis.com/cbtapprove';

    private bool $isTest;

    private string $mid;

    private string $signKey;

    private string $inapiKey;

    private string $inapiIv;

    private bool $japanEnabled;

    private string $japanMid;

    private string $japanCbtKey;

    public function __construct(PluginSettingsService $pluginSettingsService)
    {
        $settings = $pluginSettingsService->get(self::PLUGIN_IDENTIFIER) ?? [];
        $this->isTest = $settings['is_test_mode'] ?? true;
        $this->mid = $this->isTest
            ? ($settings['test_mid'] ?? '')
            : $this->buildLiveMid($settings['live_mid'] ?? '');
        $this->signKey = $this->isTest
            ? ($settings['test_sign_key'] ?? '')
            : ($settings['live_sign_key'] ?? '');
        $this->inapiKey = $this->isTest
            ? ($settings['test_iniapi_key'] ?? '')
            : ($settings['live_iniapi_key'] ?? '');
        $this->inapiIv = $this->isTest
            ? ($settings['test_iniapi_iv'] ?? '')
            : ($settings['live_iniapi_iv'] ?? '');
        $this->japanEnabled = $settings['japan_enabled'] ?? false;
        $this->japanMid = $this->isTest
            ? ($settings['test_japan_mid'] ?? '')
            : ($settings['live_japan_mid'] ?? '');
        $this->japanCbtKey = $this->isTest
            ? ($settings['test_japan_sign_key'] ?? '')
            : ($settings['live_japan_sign_key'] ?? '');
    }

    public function getMid(): string
    {
        return $this->mid;
    }

    public function getJapanMid(): string
    {
        return $this->japanMid;
    }

    public function isJapanEnabled(): bool
    {
        return $this->japanEnabled;
    }

    public function getJsUrl(): string
    {
        return $this->isTest ? self::JS_URL_TEST : self::JS_URL_LIVE;
    }

    public function getCbtAuthUrl(): string
    {
        return $this->isTest ? self::CBT_AUTH_URL_TEST : self::CBT_AUTH_URL_LIVE;
    }

    public function getCbtApproveUrl(): string
    {
        return $this->isTest ? self::CBT_APPROVE_URL_TEST : self::CBT_APPROVE_URL_LIVE;
    }

    /**
     * idc_name + 수신된 authUrl로 화이트리스트 검증 후 신뢰할 URL을 반환합니다.
     * PC와 모바일 패턴을 모두 허용합니다 (SSRF 방어).
     * 일치하는 화이트리스트 URL이 없으면 null 반환.
     */
    public function resolveIdcAuthUrl(string $idcName, string $receivedUrl = ''): ?string
    {
        $pc     = self::IDC_AUTH_URLS[$idcName] ?? null;
        $mobile = self::IDC_MOBILE_AUTH_URLS[$idcName] ?? null;

        if ($receivedUrl !== '' && $receivedUrl === $mobile) {
            return $mobile;
        }

        return $pc;
    }

    /**
     * idc_name + 수신된 authUrl이 화이트리스트에 있는지 검증합니다.
     */
    public function isValidIdcAuthUrl(string $idcName, string $receivedUrl): bool
    {
        $pc     = self::IDC_AUTH_URLS[$idcName] ?? null;
        $mobile = self::IDC_MOBILE_AUTH_URLS[$idcName] ?? null;

        return $receivedUrl === $pc || $receivedUrl === $mobile;
    }

    /**
     * idc_name으로 망취소 URL을 결정합니다.
     */
    public function resolveIdcNetCancelUrl(string $idcName): ?string
    {
        return self::IDC_NET_CANCEL_URLS[$idcName] ?? null;
    }

    /**
     * mKey 생성: SHA256(signKey)
     */
    public function getMKey(): string
    {
        return hash('sha256', $this->signKey);
    }

    /**
     * 결제창 서명 생성: SHA256("oid={oid}&price={price}&timestamp={timestamp}")
     */
    public function generateSignature(string $oid, int $price, string $timestamp): string
    {
        $plain = 'oid=' . $oid . '&price=' . $price . '&timestamp=' . $timestamp;

        return hash('sha256', $plain);
    }

    /**
     * verification 생성: SHA256("oid={oid}&price={price}&signKey={signKey}&timestamp={timestamp}")
     */
    public function generateVerification(string $oid, int $price, string $timestamp): string
    {
        $plain = 'oid=' . $oid . '&price=' . $price . '&signKey=' . $this->signKey . '&timestamp=' . $timestamp;

        return hash('sha256', $plain);
    }

    /**
     * CBT 해시 데이터 생성: SHA-512(KEY + mid + timestamp + amount + orderId)
     *
     * @param string $mid       일본 결제용 MID
     * @param string $timestamp 타임스탬프 (밀리초)
     * @param int    $amount    결제 금액
     * @param string $orderId   주문번호
     */
    public function generateCbtHashData(string $mid, string $timestamp, int $amount, string $orderId): string
    {
        $plain = $this->japanCbtKey . $mid . $timestamp . (string) $amount . $orderId;

        return hash('sha512', $plain);
    }

    /**
     * 서버 승인 API 호출 (authUrl로 POST)
     *
     * @param string $authUrl   이니시스가 콜백으로 전달한 authUrl
     * @param string $authToken 이니시스가 콜백으로 전달한 authToken
     * @return array PG 응답 데이터
     * @throws \Exception API 호출 실패 시
     */
    public function authorizePayment(string $authUrl, string $authToken): array
    {
        $response = Http::asForm()->post($authUrl, [
            'authToken' => $authToken,
        ]);

        if ($response->failed()) {
            throw new \Exception('KG Inicis authorize API error: HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * CBT 승인 처리: POST /cbtapprove with mid + sid
     *
     * @param string $sid KG Inicis CBT 인증 후 반환된 세션 ID
     * @return array PG 응답 데이터
     * @throws \Exception API 호출 실패 시
     */
    public function approveCbtPayment(string $sid): array
    {
        $approveUrl = $this->getCbtApproveUrl();

        $response = Http::asForm()->post($approveUrl, [
            'mid' => $this->japanMid,
            'sid' => $sid,
        ]);

        if ($response->failed()) {
            throw new \Exception('KG Inicis CBT approve API error: HTTP ' . $response->status());
        }

        return $response->json() ?? [];
    }

    /**
     * 망취소 요청 (서버 승인 중 예외 발생 시 결제 원천 취소)
     *
     * @param string $netCancelUrl 이니시스가 콜백으로 전달한 netCancelUrl
     * @param string $authToken    인증 토큰
     */
    public function sendNetCancel(string $netCancelUrl, string $authToken): void
    {
        try {
            Http::asForm()->post($netCancelUrl, [
                'authToken' => $authToken,
            ]);
        } catch (\Throwable $e) {
            Log::error('KG Inicis net cancel failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * 결제 취소 API 호출 (INIAPI)
     *
     * @param string      $tid         거래번호 (이니시스 TID)
     * @param string      $payMethod   결제수단 (Card/Vacct 등)
     * @param int|null    $cancelPrice 취소 금액 (null이면 전액 취소)
     * @param string      $msg         취소 사유
     * @return array PG 응답 데이터
     * @throws \Exception API 호출 실패 시
     */
    public function cancelPayment(
        string $tid,
        string $payMethod,
        ?int $cancelPrice = null,
        string $msg = '관리자 취소',
    ): array {
        $type = $cancelPrice === null ? 'Refund' : 'PartialRefund';
        $timestamp = (string) round(microtime(true) * 1000);
        $clientIp = request()->ip() ?? '127.0.0.1';

        $hashBase = $this->inapiKey . $type . $payMethod . $timestamp . $clientIp . $this->mid . $tid;
        if ($cancelPrice !== null) {
            $hashBase .= (string) $cancelPrice;
        }
        $hashData = hash('sha512', $hashBase);

        $params = [
            'type' => $type,
            'paymethod' => $payMethod,
            'timestamp' => $timestamp,
            'clientIp' => $clientIp,
            'mid' => $this->mid,
            'tid' => $tid,
            'msg' => $msg,
            'hashData' => $hashData,
        ];

        if ($cancelPrice !== null) {
            $params['price'] = $cancelPrice;
        }

        $apiUrl = $this->isTest ? self::API_URL_TEST : self::API_URL_LIVE;

        $response = Http::asForm()->post($apiUrl, $params);

        if ($response->failed()) {
            throw new \Exception('KG Inicis cancel API error: HTTP ' . $response->status());
        }

        $result = $response->json() ?? [];

        if (($result['resultCode'] ?? '') !== '00') {
            Log::error('KG Inicis cancel failed', [
                'result_code' => $result['resultCode'] ?? 'UNKNOWN',
                'result_msg' => $result['resultMsg'] ?? '',
                'tid' => $tid,
            ]);
            throw new \Exception($result['resultMsg'] ?? 'KG Inicis cancel failed');
        }

        return $result;
    }

    private function buildLiveMid(string $suffix): string
    {
        if ($suffix === '') {
            return '';
        }

        return str_starts_with($suffix, self::LIVE_MID_PREFIX) ? $suffix : self::LIVE_MID_PREFIX . $suffix;
    }
}
