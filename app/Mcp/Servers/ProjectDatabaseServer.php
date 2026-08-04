<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ApiUserLookupTool;
use App\Mcp\Tools\CreateProjectTaskTool;
use App\Mcp\Tools\CustomerInvoicesTool;
use App\Mcp\Tools\CustomerRevenueTool;
use App\Mcp\Tools\CustomerSummaryTool;
use App\Mcp\Tools\ListProjectsTool;
use App\Mcp\Tools\LookupContactByEmailTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;
use Laravel\Mcp\Server\Prompt;
use Laravel\Mcp\Server\Tool;

#[Name('Project Support Server')]
#[Version('2.0.0')]
#[Instructions(<<<'TXT'
This server exposes a project's customer data through that project's read-only
support API, and lets you act on insights from incoming email.

Typical workflow:
1. Call "list-projects" to find the project key (e.g. "REV" for Revboost) and
   confirm it has a support API.
2. Use "lookup-contact-by-email" or "api-user-lookup" with the sender's address
   to resolve them to a customer id.
3. Use "customer-summary", "customer-revenue" and "customer-invoices" with that
   id to gather the details behind their question.
4. If a follow-up is warranted, use "create-project-task" to log an action item
   in the app. Customer data itself is never modified.

There is no direct database access: whatever the support API does not expose
cannot be retrieved. Never invent values to fill a gap — say what is missing.
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
        LookupContactByEmailTool::class,
        CreateProjectTaskTool::class,
        // Support-API tools — the only route to customer data.
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
