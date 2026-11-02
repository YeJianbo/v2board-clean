<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class KnowledgeSave extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'id' => 'nullable|integer|min:0|max:2147483647',
            'category' => 'required|string|max:255',
            'language' => 'required|string|max:5',
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'show' => 'nullable|boolean',
            'sort' => 'nullable|integer|min:-2147483648|max:2147483647',
        ];
    }

    public function messages()
    {
        return [
            'title.required' => '标题不能为空',
            'category.required' => '分类不能为空',
            'body.required' => '内容不能为空',
            'language.required' => '语言不能为空',
            'show.boolean' => '显示状态必须为布尔值'
        ];
    }
}
