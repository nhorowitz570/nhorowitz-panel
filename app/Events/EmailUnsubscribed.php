<?php

namespace App\Events;

final readonly class EmailUnsubscribed
{
    public function __construct(public string $email) {}
}
