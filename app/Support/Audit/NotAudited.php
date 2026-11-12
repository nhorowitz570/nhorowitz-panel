<?php

namespace App\Support\Audit;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class NotAudited
{
    public function __construct(public string $why) {}
}
