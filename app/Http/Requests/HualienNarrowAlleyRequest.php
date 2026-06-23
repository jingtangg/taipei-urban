<?php

namespace App\Http\Requests;

use App\Enums\HualienTownship;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class HualienNarrowAlleyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'township' => ['nullable', 'string', Rule::in(HualienTownship::ALL)],
        ];
    }

    public function messages(): array
    {
        return [
            'township.in' => '鄉鎮市區名稱無效，請輸入花蓮縣 13 個鄉鎮市區之一',
        ];
    }
}
