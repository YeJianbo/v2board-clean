<?php

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdate extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'auto_renewal' => 'boolean',
            'remind_expire' => 'boolean',
            'remind_traffic' => 'boolean'
        ];
    }

    public function messages()
    {
        return [
            'auto_renewal.boolean' => __('Invalid automatic renewal option'),
            'remind_expire.boolean' => __('Incorrect format of expiration reminder'),
            'remind_traffic.boolean' => __('Incorrect traffic alert format')
        ];
    }
}
