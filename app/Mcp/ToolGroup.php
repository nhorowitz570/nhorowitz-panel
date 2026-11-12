<?php

namespace App\Mcp;

interface ToolGroup
{
    /** @return list<class-string<Tool>> */
    public static function tools(): array;
}
