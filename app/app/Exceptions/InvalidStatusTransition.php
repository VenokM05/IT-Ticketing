<?php

namespace App\Exceptions;

use App\Enums\TicketStatus;
use RuntimeException;

class InvalidStatusTransition extends RuntimeException
{
    public function __construct(public readonly TicketStatus $from, public readonly TicketStatus $to)
    {
        parent::__construct(sprintf(
            'Invalid ticket status transition from [%s] to [%s].',
            $from->value,
            $to->value,
        ));
    }
}
