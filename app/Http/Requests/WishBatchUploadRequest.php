<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class WishBatchUploadRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    public function rules(): array {
        return [
            'file' => ['required','file','mimes:pdf,xlsx,xls','max:20480'], // 20MB
        ];
    }
}
