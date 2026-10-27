<?php

namespace ErnestDefoe\Millwright\Exception;

use Flarum\Foundation\ErrorHandling\HandledError;

class RefusedExceptionHandler
{
    public function handle(RefusedException $e): HandledError
    {
        return (new HandledError($e, $e->getType(), $e->getStatus()))
            ->withDetails([['detail' => $e->getMessage()]]);
    }
}
