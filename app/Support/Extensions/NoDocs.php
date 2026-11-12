<?php

namespace App\Support\Extensions;

final class NoDocs implements DocsSource
{
    public function search(string $question, int $limit): array
    {
        return [];
    }
}
