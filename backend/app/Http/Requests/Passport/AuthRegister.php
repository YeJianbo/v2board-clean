<?php

namespace App\Http\Requests\Passport;

use Illuminate\Foundation\Http\FormRequest;

class AuthRegister extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email' => ['bail', 'required', 'string', 'max:64', 'email:strict', function ($attribute, $value, $fail) {
                if (\App\Models\User::isConnectivityTestEmail($value)) $fail(__('Email format is incorrect'));
            }],
            'password' => 'bail|required|string|min:8|max:1024',
            'invite_code' => 'nullable|string|max:64',
            'email_code' => 'nullable|string|digits:6',
            'recaptcha_data' => 'nullable|string|max:8192'
        ];
    }

    public function messages()
    {
        return [
            'email.required' => __('Email can not be empty'),
            'email.email' => __('Email format is incorrect'),
            'password.required' => __('Password can not be empty'),
            'password.min' => __('Password must be greater than 8 digits')
        ];
    }
}
