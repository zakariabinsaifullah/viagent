<?php
/**
 * Supported AI apps and how to connect each one.
 *
 * To add a client, add an entry here — the Connect wizard reads everything from
 * this list. Templates may use {{url}}, {{key}} and {{name}} placeholders.
 *
 * Fields:
 * - name, tagline, color, mark: display (mark = short text shown in the app tile).
 * - group:    "app" | "editor" | "cli" | "other".
 * - auth:     "key" (connection key in a config) or "oauth" (the app signs in to WordPress).
 * - format:   "command" | "json" | "toml" — language of the snippet.
 * - file:     where the snippet goes (optional).
 * - steps:    plain-language steps shown before the snippet.
 * - template: the snippet.
 * - deeplink: "cursor" | "vscode" for a one-click install button (optional).
 * - needs:    extra requirement shown as a notice (optional).
 * - docs:     link to the client's MCP docs (optional).
 * - verify:   how to check the app sees your site (plain language).
 * - tool_limit: most tools the app accepts; above it, compact mode is used.
 *
 * The {{local_env}} placeholder adds an environment variable for sites on this
 * computer, whose development HTTPS certificate Node.js does not trust.
 *
 * @package Viagent
 */

defined( 'ABSPATH' ) || exit;

$viagent_http_headers = '"headers": {
        "Authorization": "Bearer {{key}}"
      }';

$viagent_bridge = '{
  "mcpServers": {
    "{{name}}": {
      "command": "npx",
      "args": ["-y", "mcp-remote", "{{url}}", "--header", "Authorization:${VIAGENT_AUTH}"],
      "env": {
        "VIAGENT_AUTH": "Bearer {{key}}"{{local_env}}
      }
    }
  }
}';

return array(
	'claude-code'  => array(
		'name'      => 'Claude Code',
		'mark'      => 'CC',
		'verify'    => __( 'Start Claude Code by typing claude, then type /mcp. “{{name}}” should say connected.', 'viagent' ),
		'tagline'   => __( 'Anthropic\'s coding agent in your terminal', 'viagent' ),
		'color'     => '#d97757',
		'group'     => 'cli',
		'auth'      => 'key',
		'format'    => 'command',
		'steps'     => array(
			__( 'Open a terminal.', 'viagent' ),
			__( 'Paste this command and press Enter.', 'viagent' ),
			__( 'Start Claude Code and ask it about your site.', 'viagent' ),
		),
		'template'  => 'claude mcp add --transport http {{name}} {{url}} --header "Authorization: Bearer {{key}}"',
		'local_tip' => __( 'This site runs on your computer with a development certificate. If Claude Code reports a certificate error, start it with: NODE_TLS_REJECT_UNAUTHORIZED=0 claude', 'viagent' ),
		'docs'      => 'https://docs.anthropic.com/en/docs/claude-code/mcp',
	),
	'claude'       => array(
		'name'     => 'Claude Desktop',
		'mark'     => 'CD',
		'verify'   => __( 'Quit Claude Desktop completely (Cmd+Q on Mac, or Quit from the system tray on Windows) and open it again. In a new chat, click the + button (lower left) → Connectors: “{{name}}” should be listed and switched on.', 'viagent' ),
		'tagline'  => __( 'The Claude app for Mac and Windows', 'viagent' ),
		'color'    => '#c96442',
		'group'    => 'app',
		'auth'     => 'key',
		'format'   => 'json',
		'file'     => 'claude_desktop_config.json',
		'needs'    => __( 'Requires Node.js 18 or newer on your computer (nodejs.org). If your site is online, the Claude.ai option is easier — it also works in Claude Desktop, with no key or Node.js.', 'viagent' ),
		'steps'    => array(
			__( 'In Claude Desktop, open Settings → Developer → Edit Config.', 'viagent' ),
			__( 'Paste this into the file. If it already has "mcpServers", add just the inner block.', 'viagent' ),
			__( 'Save the file.', 'viagent' ),
		),
		'template' => $viagent_bridge,
		'docs'     => 'https://modelcontextprotocol.io/quickstart/user',
	),
	'claude-web'   => array(
		'name'    => 'Claude.ai',
		'mark'    => 'C.ai',
		'verify'  => __( 'In a new chat, click the + button (lower left) → Connectors and make sure your site’s connector is switched on.', 'viagent' ),
		'tagline' => __( 'Claude on the web, desktop and mobile, via Connectors', 'viagent' ),
		'color'   => '#b4583a',
		'group'   => 'app',
		'auth'    => 'oauth',
		'format'  => 'command',
		'steps'   => array(
			__( 'In Claude, open Customize → Connectors and click the + button next to Connectors. (On Team and Enterprise plans, an owner adds connectors in Organization settings.)', 'viagent' ),
			__( 'Give it a name (e.g. your site name), paste the connector URL above, and click Add. Leave the advanced OAuth fields empty.', 'viagent' ),
			__( 'Click Connect. A WordPress window opens: sign in, choose what Claude may do, and click Approve.', 'viagent' ),
			__( 'In a chat, click + → Connectors to switch it on, then ask Claude about your site.', 'viagent' ),
		),
		'needs'   => __( 'Claude connects from Anthropic’s cloud, so your site must be online and reachable from the internet over HTTPS.', 'viagent' ),
		'docs'    => 'https://support.claude.com/en/articles/11175166-get-started-with-custom-connectors-using-remote-mcp',
	),
	'chatgpt'      => array(
		'name'    => 'ChatGPT',
		'mark'    => 'GPT',
		'verify'  => __( 'In a new chat, open the composer’s Developer mode tools and select your site’s app. You can switch its tools on or off on the app’s page under Plugins.', 'viagent' ),
		'tagline' => __( 'OpenAI\'s assistant, via developer mode', 'viagent' ),
		'color'   => '#10a37f',
		'group'   => 'app',
		'auth'    => 'oauth',
		'format'  => 'command',
		'needs'   => __( 'Needs ChatGPT on the web with a Plus, Pro, Business, Enterprise or Education plan. On Business and Enterprise workspaces, an admin must allow developer mode first. ChatGPT renames these menus from time to time — if a step looks different, open OpenAI’s guide below.', 'viagent' ),
		'steps'   => array(
			__( 'In ChatGPT on the web, open Settings → Security and login and turn on Developer mode.', 'viagent' ),
			__( 'Open Plugins, click the + button and choose “Create app” (not “Create plugin” or “Upload plugin”). If asked, choose “Create MCP app”.', 'viagent' ),
			__( 'Enter a name (e.g. your site name) and a short description. Under Connection, paste the connector URL above as the MCP server URL (public endpoint), and choose OAuth if asked how to sign in.', 'viagent' ),
			__( 'Create it. A WordPress window opens: sign in, choose what ChatGPT may do, and click Approve.', 'viagent' ),
		),
		'docs'    => 'https://developers.openai.com/api/docs/guides/developer-mode',
	),
	'cursor'       => array(
		'name'       => 'Cursor',
		'mark'       => 'Cu',
		'verify'     => __( 'Open Cursor Settings → MCP. “{{name}}” should have a green dot. Chat in Agent mode.', 'viagent' ),
		'tagline'    => __( 'AI code editor', 'viagent' ),
		'color'      => '#1f1f1f',
		'group'      => 'editor',
		'auth'       => 'key',
		'format'     => 'json',
		'file'       => '~/.cursor/mcp.json',
		'deeplink'   => 'cursor',
		'tool_limit' => 40,
		'steps'      => array(
			__( 'Click "Add to Cursor" and confirm in Cursor.', 'viagent' ),
			__( 'Or open Cursor Settings → MCP → Add new MCP server and paste this.', 'viagent' ),
		),
		'template'   => '{
  "mcpServers": {
    "{{name}}": {
      "url": "{{url}}",
      ' . $viagent_http_headers . '
    }
  }
}',
		'docs'       => 'https://docs.cursor.com/context/mcp',
	),
	'vscode'       => array(
		'name'     => 'VS Code',
		'mark'     => 'VS',
		'verify'   => __( 'Open Copilot Chat, switch to Agent mode and click the tools icon: “{{name}}” should be listed. Click Start if VS Code asks.', 'viagent' ),
		'tagline'  => __( 'GitHub Copilot agent mode', 'viagent' ),
		'color'    => '#0078d4',
		'group'    => 'editor',
		'auth'     => 'key',
		'format'   => 'json',
		'file'     => '.vscode/mcp.json',
		'deeplink' => 'vscode',
		'steps'    => array(
			__( 'Click "Add to VS Code" and confirm in VS Code.', 'viagent' ),
			__( 'Or run "MCP: Open User Configuration" from the Command Palette and paste this.', 'viagent' ),
		),
		'template' => '{
  "servers": {
    "{{name}}": {
      "type": "http",
      "url": "{{url}}",
      ' . $viagent_http_headers . '
    }
  }
}',
		'docs'     => 'https://code.visualstudio.com/docs/copilot/chat/mcp-servers',
	),
	'windsurf'     => array(
		'name'     => 'Windsurf',
		'mark'     => 'Ws',
		'verify'   => __( 'Open the MCP panel in Cascade: “{{name}}” should be listed as active.', 'viagent' ),
		'tagline'  => __( 'AI code editor by Codeium', 'viagent' ),
		'color'    => '#0b9d8a',
		'group'    => 'editor',
		'auth'     => 'key',
		'format'   => 'json',
		'file'     => '~/.codeium/windsurf/mcp_config.json',
		'steps'    => array(
			__( 'In Windsurf, open Settings → Cascade → MCP Servers → View raw config.', 'viagent' ),
			__( 'Paste this and save. Click Refresh in the MCP panel.', 'viagent' ),
		),
		'template' => '{
  "mcpServers": {
    "{{name}}": {
      "serverUrl": "{{url}}",
      ' . $viagent_http_headers . '
    }
  }
}',
		'docs'     => 'https://docs.windsurf.com/windsurf/cascade/mcp',
	),
	'codex'        => array(
		'name'     => 'Codex',
		'mark'     => 'Cx',
		'verify'   => __( 'Start Codex by typing codex, then type /mcp. “{{name}}” should be listed.', 'viagent' ),
		'tagline'  => __( 'OpenAI\'s coding agent (CLI and IDE)', 'viagent' ),
		'color'    => '#202123',
		'group'    => 'cli',
		'auth'     => 'key',
		'format'   => 'toml',
		'file'     => '~/.codex/config.toml',
		'steps'    => array(
			__( 'Open ~/.codex/config.toml in a text editor (create it if missing).', 'viagent' ),
			__( 'Paste this at the end and save.', 'viagent' ),
			__( 'Restart Codex.', 'viagent' ),
		),
		'template' => '[mcp_servers.{{name}}]
url = "{{url}}"
http_headers = { "Authorization" = "Bearer {{key}}" }',
		'docs'     => 'https://github.com/openai/codex/blob/main/docs/config.md',
	),
	'gemini-cli'   => array(
		'name'     => 'Gemini CLI',
		'mark'     => 'Gm',
		'verify'   => __( 'Start Gemini CLI by typing gemini, then type /mcp. “{{name}}” should be listed as connected.', 'viagent' ),
		'tagline'  => __( 'Google\'s AI agent in your terminal', 'viagent' ),
		'color'    => '#4285f4',
		'group'    => 'cli',
		'auth'     => 'key',
		'format'   => 'json',
		'file'     => '~/.gemini/settings.json',
		'steps'    => array(
			__( 'Open ~/.gemini/settings.json in a text editor.', 'viagent' ),
			__( 'Paste this (merge with "mcpServers" if it exists) and save.', 'viagent' ),
			__( 'Restart Gemini CLI and run /mcp to check.', 'viagent' ),
		),
		'template' => '{
  "mcpServers": {
    "{{name}}": {
      "httpUrl": "{{url}}",
      ' . $viagent_http_headers . '
    }
  }
}',
		'docs'     => 'https://github.com/google-gemini/gemini-cli/blob/main/docs/tools/mcp-server.md',
	),
	'antigravity'  => array(
		'name'     => 'Antigravity',
		'mark'     => 'Ag',
		'verify'   => __( 'Open the MCP Servers panel: “{{name}}” should be listed. Then start a new Agent conversation so it picks up the server.', 'viagent' ),
		'tagline'  => __( 'Google\'s agent-first IDE', 'viagent' ),
		'color'    => '#34a853',
		'group'    => 'editor',
		'auth'     => 'key',
		'format'   => 'json',
		'file'     => '~/.gemini/antigravity-ide/mcp_config.json',
		'steps'    => array(
			__( 'In Antigravity, open the Agent panel\'s "…" menu → MCP Servers → Manage MCP Servers → View raw config. (Or open ~/.gemini/antigravity-ide/mcp_config.json — older versions use ~/.gemini/antigravity/mcp_config.json.)', 'viagent' ),
			__( 'Paste this (merge with "mcpServers" if it exists) and save.', 'viagent' ),
		),
		'template' => '{
  "mcpServers": {
    "{{name}}": {
      "serverUrl": "{{url}}",
      ' . $viagent_http_headers . '
    }
  }
}',
	),
	'opencode'     => array(
		'name'     => 'OpenCode',
		'mark'     => 'OC',
		'verify'   => __( 'Start OpenCode by typing opencode. “{{name}}” should appear in its MCP server list.', 'viagent' ),
		'tagline'  => __( 'Open-source AI coding agent', 'viagent' ),
		'color'    => '#6b46c1',
		'group'    => 'cli',
		'auth'     => 'key',
		'format'   => 'json',
		'file'     => 'opencode.json',
		'steps'    => array(
			__( 'Open opencode.json in your project, or ~/.config/opencode/opencode.json for all projects.', 'viagent' ),
			__( 'Paste this (merge with "mcp" if it exists) and save.', 'viagent' ),
		),
		'template' => '{
  "mcp": {
    "{{name}}": {
      "type": "remote",
      "url": "{{url}}",
      "enabled": true,
      ' . $viagent_http_headers . '
    }
  }
}',
		'docs'     => 'https://opencode.ai/docs/mcp-servers/',
	),
	'openclaw'     => array(
		'name'     => 'OpenClaw',
		'mark'     => 'Cw',
		'verify'   => __( 'Open OpenClaw’s MCP settings: “{{name}}” should be listed as connected.', 'viagent' ),
		'tagline'  => __( 'Personal AI assistant', 'viagent' ),
		'color'    => '#e5484d',
		'group'    => 'app',
		'auth'     => 'key',
		'format'   => 'json',
		'needs'    => __( 'Uses the mcp-remote bridge, which requires Node.js 18 or newer.', 'viagent' ),
		'steps'    => array(
			__( 'Open OpenClaw\'s MCP server settings.', 'viagent' ),
			__( 'Add a server using this configuration and save.', 'viagent' ),
		),
		'template' => $viagent_bridge,
	),
	'command-code' => array(
		'name'     => 'Command Code',
		'mark'     => '⌘',
		'verify'   => __( 'Open Command Code’s MCP settings: “{{name}}” should be listed as connected.', 'viagent' ),
		'tagline'  => __( 'AI coding agent', 'viagent' ),
		'color'    => '#f59e0b',
		'group'    => 'cli',
		'auth'     => 'key',
		'format'   => 'json',
		'steps'    => array(
			__( 'Open Command Code\'s MCP server settings.', 'viagent' ),
			__( 'Add a remote (HTTP) server with this configuration.', 'viagent' ),
		),
		'template' => '{
  "mcpServers": {
    "{{name}}": {
      "type": "http",
      "url": "{{url}}",
      ' . $viagent_http_headers . '
    }
  }
}',
	),
	'other'        => array(
		'name'     => __( 'Other app', 'viagent' ),
		'mark'     => '+',
		'verify'   => __( 'Open your app’s MCP settings: “{{name}}” should be listed as connected.', 'viagent' ),
		'tagline'  => __( 'Any MCP-compatible app, e.g. for DeepSeek or local models', 'viagent' ),
		'color'    => '#646970',
		'group'    => 'other',
		'auth'     => 'key',
		'format'   => 'json',
		'needs'    => __( 'Models like DeepSeek connect through an MCP-capable app (for example OpenCode, Cherry Studio or LibreChat). Add your site there as a "Streamable HTTP" server.', 'viagent' ),
		'steps'    => array(
			__( 'Open your app\'s MCP server settings.', 'viagent' ),
			__( 'Add a "Streamable HTTP" server with the URL and header below.', 'viagent' ),
		),
		'template' => '{
  "mcpServers": {
    "{{name}}": {
      "type": "http",
      "url": "{{url}}",
      ' . $viagent_http_headers . '
    }
  }
}',
	),
);
