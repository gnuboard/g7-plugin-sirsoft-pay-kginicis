<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Kginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class AuthCallbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'resultCode' => ['required', 'string'],
            'resultMsg'  => ['nullable', 'string'],
            // authToken/authUrl/netCancelUrl/idc_name 은 실패 콜백 시 누락될 수 있으므로 nullable.
            // 화이트리스트 검증(SSRF 방어)은 컨트롤러에서 로그와 함께 수행.
            'authToken'    => ['nullable', 'string'],
            'authUrl'      => ['nullable', 'string'],
            'netCancelUrl' => ['nullable', 'string'],
            'idc_name'     => ['nullable', 'string'],
            'MOID'         => ['required', 'string'],
            'TotPrice'     => ['required', 'integer', 'min:0'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        Log::error('KG Inicis: AuthCallbackRequest validation failed', [
            'errors' => $validator->errors()->toArray(),
            'input'  => array_keys($this->all()),
        ]);

        throw new \Illuminate\Validation\ValidationException($validator);
    }
}
