<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserGenerate extends FormRequest
{
    protected function prepareForValidation(): void
    {
        foreach (['email_prefix', 'email_suffix'] as $field) {
            if (is_string($this->input($field))) $this->merge([$field => trim($this->input($field))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'generate_count' => 'nullable|required_without:email_prefix|integer|min:1|max:500',
            'expired_at' => 'nullable|integer',
            'plan_id' => 'nullable|integer|min:0|max:2147483647',
            'email_prefix' => 'nullable|required_without:generate_count|string|max:64',
            'email_suffix' => 'required|string|max:253',
            'password' => 'nullable|string|max:1024',
            'download_csv' => 'sometimes|boolean',
            'async' => 'sometimes|boolean',
            'request_id' => [Rule::requiredIf($this->boolean('async')), 'nullable', 'uuid'],
        ];
    }

    public function messages()
    {
        return [
            'generate_count.integer' => '生成数量必须为数字',
            'generate_count.min' => '生成数量最少为1个',
            'generate_count.max' => '生成数量最大为500个'
        ];
    }
}
