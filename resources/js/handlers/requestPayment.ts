/* eslint-disable @typescript-eslint/no-explicit-any */

interface PgPaymentData {
    order_number: string;
    order_name: string;
    amount: number;
    currency?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
}

interface RequestPaymentParams {
    pgPaymentData: PgPaymentData;
    paymentMethod?: string;
}

interface TemplateLocalState {
    paymentMethod?: string;
}

interface ClientConfig {
    mid: string;
    sdk_url: string;
    callback_urls: {
        signature: string;
        callback: string;
        cbt_hash_data: string;
        cbt_callback: string;
        cbt_auth_url: string;
    };
    japan_enabled: boolean;
    japan_mid: string;
}

interface SignatureResponse {
    signature: string;
    verification: string;
    mKey: string;
}

interface CbtHashDataResponse {
    hash_data: string;
}

declare global {
    interface Window {
        INIStdPay: any;
        __templateApp?: {
            globalState?: {
                _local?: TemplateLocalState;
            };
        };
    }
}

function loadScript(src: string): Promise<void> {
    return new Promise((resolve, reject) => {
        if (document.querySelector(`script[src="${src}"]`)) {
            resolve();
            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
        document.head.appendChild(script);
    });
}

function submitForm(action: string, fields: Record<string, string>): void {
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = action;

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}

const GOPAYMETHOD_MAP: Record<string, string> = {
    card:  'Card',
    vbank: 'VBank',
    bank:  'DirectBank',
    phone: 'HPP',
};

/**
 * KG 이니시스 한국 표준결제 (INIStdPay 팝업)
 */
async function requestKoreanPayment(
    G7Core: any,
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
): Promise<void> {
    const timestamp = String(Math.floor(Date.now()));

    const signatureJson: { data: SignatureResponse } = await G7Core.api.post(
        config.callback_urls.signature,
        { oid: pgPaymentData.order_number, price: pgPaymentData.amount, timestamp },
    );

    const { signature, verification, mKey } = signatureJson.data;

    if (!window.INIStdPay) {
        await loadScript(config.sdk_url);
    }

    if (!window.INIStdPay) {
        await new Promise<void>((resolve) => setTimeout(resolve, 100));
    }

    if (!window.INIStdPay) {
        throw new Error('INIStdPay SDK not available');
    }

    const callbackUrl = window.location.origin + config.callback_urls.callback;
    const shopBase = (window as any).G7Core?.state?.get?.('templateSettings')?.shopBase ?? '/shop';
    const orderCloseUrl = window.location.origin + shopBase + '/checkout';
    const formId = 'kginicis_pay_form_' + Date.now();

    const form = document.createElement('form');
    form.id = formId;
    form.method = 'POST';
    form.acceptCharset = 'euc-kr';

    const fields: Record<string, string> = {
        version: '1.0',
        mid: config.mid,
        oid: pgPaymentData.order_number,
        goodname: pgPaymentData.order_name,
        price: String(pgPaymentData.amount),
        currency: pgPaymentData.currency === 'KRW' ? 'WON' : (pgPaymentData.currency ?? 'WON'),
        buyername: pgPaymentData.customer_name ?? '',
        buyeremail: pgPaymentData.customer_email ?? '',
        buyertel: pgPaymentData.customer_phone ?? '',
        timestamp,
        signature,
        verification,
        mKey,
        returnUrl: callbackUrl,
        closeUrl: orderCloseUrl,
        gopaymethod: GOPAYMETHOD_MAP[paymentMethod] ?? 'Card',
        acceptmethod: paymentMethod === 'phone' ? 'HPP(1):centerCd(Y)' : 'centerCd(Y)',
        payViewType: 'overlay',
        use_chkfake: 'Y',
        charset: 'UTF-8',
    };

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);

    window.INIStdPay.pay(formId);
}

/**
 * KG 이니시스 CBT (일본 엔 결제) 처리
 *
 * 페이지 전환 방식: /cbtauth 로 POST 폼 전송 → KG 이니시스 인증 → returnUrl 로 리다이렉트 → 서버 승인
 */
async function requestCbtPayment(
    G7Core: any,
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
): Promise<void> {
    const japanMid = config.japan_mid;
    const timestamp = String(Math.floor(Date.now()));

    const hashResponse: { data: CbtHashDataResponse } = await G7Core.api.post(
        config.callback_urls.cbt_hash_data,
        { oid: pgPaymentData.order_number, price: pgPaymentData.amount, timestamp },
    );

    const { hash_data: hashData } = hashResponse.data;

    const returnUrl =
        window.location.origin +
        config.callback_urls.cbt_callback +
        `?oid=${encodeURIComponent(pgPaymentData.order_number)}&amount=${pgPaymentData.amount}`;

    submitForm(config.callback_urls.cbt_auth_url, {
        cbtType: 'JPPG',
        mid: japanMid,
        timestamp,
        returnUrl,
        buyerName: pgPaymentData.customer_name ?? '',
        buyerEmail: pgPaymentData.customer_email ?? '',
        goodName: pgPaymentData.order_name,
        amount: String(pgPaymentData.amount),
        orderId: pgPaymentData.order_number,
        hashData,
        extraData: JSON.stringify({}),
    });
}

/**
 * KG 이니시스 결제창 호출 핸들러
 *
 * 체크아웃 레이아웃에서 주문 생성 API 성공 후 호출됩니다:
 *   handler: "sirsoft-pay-kginicis.requestPayment"
 *   params: { pgPaymentData: response.data.pg_payment_data }
 *
 * 결제 흐름:
 *   - KRW/WON: INIStdPay 팝업 (표준결제)
 *   - JPY (japan_enabled): CBT 페이지 전환 결제
 */
export async function requestPaymentHandler(action: any, _context?: any): Promise<void> {
    const { pgPaymentData, paymentMethod: paramPaymentMethod } = (action.params || {}) as RequestPaymentParams;

    if (!pgPaymentData) {
        return;
    }

    const localState = window.__templateApp?.globalState?._local;
    const paymentMethod = paramPaymentMethod ?? localState?.paymentMethod ?? 'card';

    const G7Core = (window as any).G7Core;

    try {
        const configJson = await G7Core.api.get('/modules/sirsoft-ecommerce/payments/client-config/kginicis');

        if (!configJson.data) {
            throw new Error('Failed to fetch KG Inicis client config');
        }

        const config: ClientConfig = configJson.data;
        const currency = pgPaymentData.currency ?? 'WON';
        const isJapan = currency === 'JPY' && config.japan_enabled && !!config.japan_mid;

        if (isJapan) {
            await requestCbtPayment(G7Core, config, pgPaymentData);
        } else {
            await requestKoreanPayment(G7Core, config, pgPaymentData, paymentMethod);
        }

    } catch (error: unknown) {
        const errorMessage = error instanceof Error ? error.message : 'Unknown error';
        G7Core?.state?.setLocal?.({ paymentErrorMessage: errorMessage, isSubmittingOrder: false });
        G7Core?.modal?.open?.('kginicis_payment_error_modal');
    }
}
