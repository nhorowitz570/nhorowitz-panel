<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Project\AddProjectResource;
use App\Mcp\Tools\Project\AddToProject;
use App\Mcp\Tools\Project\ConnectProjectApp;
use App\Mcp\Tools\Project\CreateProject;
use App\Mcp\Tools\Project\GetProject;
use App\Mcp\Tools\Project\ListProjects;

final class ProjectTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListProjects::class,
            GetProject::class,
            CreateProject::class,
            AddToProject::class,
            AddProjectResource::class,
            ConnectProjectApp::class,
        ];
    }
}
