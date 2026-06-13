<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ApiUserLookupTool;
use App\Mcp\Tools\CreateProjectTaskTool;
use App\Mcp\Tools\CustomerInvoicesTool;
use App\Mcp\Tools\CustomerRevenueTool;
use App\Mcp\Tools\CustomerSummaryTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\LookupContactByEmailTool;
use App\Mcp\Tools\QueryProjectDatabaseTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Project Database Server')]
#[Version('1.0.0')]
#[Instructions(<<<'TXT'
This server exposes a project's external (customer) database and lets you act on
insights from incoming email.

Typical workflow:
1. Call "list-projects" to find the project key (e.g. "REV" for Revboost) and
   confirm it has an external database.
2. Use "lookup-contact-by-email" with the sender's address to locate that person
   in the project's database, or "query-project-database" to run a read-only
   SELECT (the database is strictly read-only — writes are rejected).
3. If a follow-up is warranted, use "create-project-task" to log an action item
   in the app. The external customer database is never modified.

Always discover the schema first (e.g. `SHOW TABLES`, then `DESCRIBE <table>`)
before writing a query.
TXT)]
class ProjectDatabaseServer extends Server
{
    /**
     * The tools registered with this MCP server.
     *
     * @var array<int, class-string<Tool>>
     */
    protected array $tools = [
        ListProjectsTool::class,
        QueryProjectDatabaseTool::class,
        LookupContactByEmailTool::class,
        CreateProjectTaskTool::class,
        // External support-API tools (used when a project has an API configured).
        ApiUserLookupTool::class,
        CustomerSummaryTool::class,
        CustomerRevenueTool::class,
        CustomerInvoicesTool::class,
    ];

    /**
     * @var array<int, class-string<Server\Resource>>
     */
    protected array $resources = [
        //
    ];

    /**
     * @var array<int, class-string<Prompt>>
     */
    protected array $prompts = [
        //
    ];
}
