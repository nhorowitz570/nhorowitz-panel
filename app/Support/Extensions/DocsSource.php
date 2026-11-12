<?php

namespace App\Support\Extensions;

interface DocsSource
{
    /** @return list<array{title: string, url: string, body: string}> */
    public function search(string $question, int $limit): array;
}
