<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderFetch extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'current' => 'sometimes|integer|min:1|max:1000000',
            'pageSize' => 'sometimes|integer|min:1|max:500',
            'is_commission' => 'sometimes|boolean',
            'filter' => 'sometimes|array|max:20',
            'filter.*' => 'array',
            'filter.*.key' => 'required|in:email,trade_no,status,commission_status,user_id,invite_user_id,callback_no,commission_balance',
            'filter.*.condition' => 'required|in:>,<,=,>=,<=,模糊,!=',
            'filter.*.value' => ['present', function ($attribute, $value, $fail) {
                if ((!is_null($value) && !is_scalar($value)) || (is_string($value) && mb_strlen($value) > 255)) {
                    $fail('筛选值必须是长度不超过255的文本或数字');
                }
            }],
        ];
    }

    public function messages()
    {
        return [
            'filter.*.key.required' => '过滤键不能为空',
            'filter.*.key.in' => '过滤键参数有误',
            'filter.*.condition.required' => '过滤条件不能为空',
            'filter.*.condition.in' => '过滤条件参数有误',
        ];
    }
}
