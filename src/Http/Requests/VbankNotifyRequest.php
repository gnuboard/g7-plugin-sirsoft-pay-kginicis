<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Kginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VbankNotifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tid' => ['required', 'string'],
            'MOID' => ['required', 'string'],
            'TotPrice' => ['required', 'integer', 'min:0'],
            'resultCode' => ['required', 'string'],
            'resultMsg' => ['nullable', 'string'],
            'vbankNum' => ['nullable', 'string'],
            'vbankName' => ['nullable', 'string'],
            'vbankExpDate' => ['nullable', 'string'],
        ];
    }
}
