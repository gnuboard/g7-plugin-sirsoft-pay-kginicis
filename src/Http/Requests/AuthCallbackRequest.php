<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Kginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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
            'resultMsg' => ['nullable', 'string'],
            'authToken' => ['required', 'string'],
            'authUrl' => ['required', 'url'],
            'netCancelUrl' => ['required', 'url'],
            'MOID' => ['required', 'string'],
            'TotPrice' => ['required', 'integer', 'min:0'],
        ];
    }

    protected function failedValidation(\Illuminate\Contracts\Validation\Validator $validator): void
    {
        throw new \Illuminate\Validation\ValidationException($validator);
    }
}
