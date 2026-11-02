<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderAssign extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'plan_id' => 'required',
            'email' => 'required',
            'total_amount' => [
                'bail', 'required',
                function ($attribute, $value, $fail) {
                    if (is_bool($value) || is_array($value) || is_object($value)) $fail('支付金额必须为整数分');
                },
                'integer', 'min:0', 'max:2147483647',
            ],
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price'
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => '订阅不能为空',
            'email.required' => '邮箱不能为空',
            'total_amount.required' => '支付金额不能为空',
            'total_amount.integer' => '支付金额必须为整数分',
            'total_amount.min' => '支付金额不能为负数',
            'total_amount.max' => '支付金额超出存储上限',
            'period.required' => '订阅周期不能为空',
            'period.in' => '订阅周期格式有误'
        ];
    }
}
