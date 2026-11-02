<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UserSendMail extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'subject' => 'required|string',
            'content' => 'required|string',
        ];
    }

    public function messages()
    {
        return [
            'subject.required' => '主题不能为空',
            'content.required' => '发送内容不能为空',
            'subject.string' => '主题必须为文本',
            'content.string' => '发送内容必须为文本',
        ];
    }
}
