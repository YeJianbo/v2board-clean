<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class NoticeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => 'nullable|integer|min:0',
            'title' => 'required|string',
            'content' => 'required|string',
            'img_url' => 'nullable|url',
            'tags' => 'nullable|array',
            'tags.*' => 'string',
            'show' => 'nullable|boolean',
            'popup' => 'nullable|boolean',
            'sort' => 'nullable|integer',
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'content.required' => '内容不能为空',
            'img_url.url' => '图片URL格式不正确',
            'tags.array' => '标签格式不正确'
        ];
    }
}
