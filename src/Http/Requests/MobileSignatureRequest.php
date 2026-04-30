<?php

declare(strict_types=1);

namespace Plugins\Sirsoft\Pay\Kginicis\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MobileSignatureRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'oid'       => ['required', 'string'],
            'price'     => ['required', 'integer', 'min:1'],
            'timestamp' => ['required', 'string'],
        ];
    }
}
