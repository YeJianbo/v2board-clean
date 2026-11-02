<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\User;

class UserFetch extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'current' => 'sometimes|integer|min:1|max:2147483647',
            'pageSize' => 'sometimes|integer|min:1|max:500',
            'sort' => ['sometimes', 'nullable', 'string', 'max:64', function ($attribute, $value, $fail) {
                if (is_string($value) && in_array(strtolower(last(explode('.', $value))), User::PASSWORD_ATTRIBUTES, true)) $fail('不能按密码字段排序');
            }],
            'filter' => 'sometimes|nullable|array|max:20',
            'filter.*.key' => 'required|in:id,email,transfer_enable,d,expired_at,uuid,token,invite_by_email,invite_user_id,plan_id,banned,remarks,is_admin',
            'filter.*.condition' => 'required|in:>,<,=,>=,<=,模糊,!=',
            'filter.*.value' => ['required', function ($attribute, $value, $fail) {
                if (!is_scalar($value) || (is_string($value) && strlen($value) > 500)) $fail('过滤值格式错误或过长');
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
            'filter.*.value.required' => '过滤值不能为空',
            'current.integer' => '页码必须为正整数',
            'current.min' => '页码必须为正整数',
            'current.max' => '页码超出支持范围',
            'pageSize.integer' => '每页数量必须为整数',
            'pageSize.min' => '每页数量必须为正整数',
            'pageSize.max' => '每页最多查询 500 位用户，请使用分页或导出',
            'sort.string' => '排序字段格式错误',
        ];
    }
}
