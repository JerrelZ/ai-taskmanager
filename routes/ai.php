<?php

use App\Mcp\Servers\ProjectDatabaseServer;
use Laravel\Mcp\Facades\Mcp;

/*
|--------------------------------------------------------------------------
| Local MCP server
|--------------------------------------------------------------------------
| Runs as an Artisan command on this machine. Point a local AI client
| (Claude Desktop / Claude Code) at: `php artisan mcp:start project-db`.
| The server runs with full application access, so only expose it to
| trusted local clients.
*/
Mcp::local('project-db', ProjectDatabaseServer::class);

/*
|--------------------------------------------------------------------------
| Web MCP server (optional, remote clients)
|--------------------------------------------------------------------------
| Uncomment to expose the same server over HTTP. Protect it with auth —
| it can read customer databases. Add Sanctum/Passport or a custom token
| middleware before enabling in production.
*/
// Mcp::web('/mcp/project-db', ProjectDatabaseServer::class)
//     ->middleware(['throttle:mcp' /* , 'auth:sanctum' */]);
