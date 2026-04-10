<?php

namespace App\Http\Requests;

use App\Enums\AlleyCategory;
use App\Enums\TaipeiDistrict;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NarrowAlleyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'district' => ['nullable', 'string', Rule::in(TaipeiDistrict::ALL)],
            'category' => ['nullable', 'string', Rule::in(AlleyCategory::ALL)],
        ];
    }

    public function messages(): array
    {
        return [
            'district.in' => '行政區名稱無效，請輸入台北市12個行政區之一',
            'category.in' => '窄巷分類無效，請輸入 紅區 或 黃區',
        ];
    }
}
