<?php

namespace App\Exceptions;

use RuntimeException;

class PlanInUseException extends RuntimeException
{
    public function __construct(string $kind)
    {
        $messages = ['orders' => '该订阅下存在订单无法删除', 'users' => '该订阅下存在用户无法删除',
            'giftcards' => '该订阅被礼品卡使用，无法删除'];
        parent::__construct($messages[$kind]);
    }
}
