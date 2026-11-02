<?php

namespace App\Exceptions;

use RuntimeException;

class GroupInUseException extends RuntimeException
{
    public string $kind;

    public function __construct(string $kind)
    {
        $this->kind = $kind;
        $messages = ['nodes' => '该组已被节点所使用，无法删除', 'plans' => '该组已被订阅所使用，无法删除',
            'users' => '该组已被用户所使用，无法删除'];
        parent::__construct($messages[$kind]);
    }
}
