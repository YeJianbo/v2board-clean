<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class OrderSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'plan_id' => 'required|integer|min:0',
            'period' => 'required|in:month_price,quarter_price,half_year_price,year_price,two_year_price,three_year_price,onetime_price,reset_price,deposit',
            'deposit_amount' => 'exclude_unless:plan_id,0|required|integer|min:1|max:9999998',
            'coupon_code' => 'nullable|string|max:255',
        ];
    }

    public function messages()
    {
        return [
            'plan_id.required' => __('Plan ID cannot be empty'),
            'period.required' => __('Plan period cannot be empty'),
            'period.in' => __('Wrong plan period')
        ];
    }
}
