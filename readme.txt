=== Viagent ===
Contributors: binsaifullah
Tags: mcp, ai, ai agent, chatgpt, claude
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Connect WordPress to Claude, ChatGPT, Cursor and other AI agents with a secure MCP server. Guided setup, safe permissions and one-click undo.

== Description ==

**Viagent turns your WordPress site into a secure MCP server, so AI agents like Claude, ChatGPT, Cursor, Codex and Gemini can work on your content for you.** Ask your AI to write blog posts, update pages, upload images, fix SEO, moderate comments or handle WooCommerce orders — in plain language, from the AI app you already use.

MCP (Model Context Protocol) is the open standard AI apps use to connect to other software. Viagent adds a native MCP server to WordPress — no external relay, no extra service, no coding.

= Connect an AI app in about a minute =

Viagent is built for site owners, not just developers:

* **Guided connect wizard** – pick your AI app, choose what it may do, and follow steps written for that exact app.
* **One-click sign-in for Claude.ai and ChatGPT** – paste your connector URL, sign in to WordPress, click Approve. No keys to copy.
* **One-click install for Cursor and VS Code**, and copy-ready setup for every other app.
* **A ready-made first message** for each app, so you know exactly what to say to your AI.
* **Live connection status** – see which apps are connected, and get help finishing a setup that hasn't connected yet.

= Works with your favourite AI apps =

Claude (Claude.ai, Claude Desktop and Claude Code), ChatGPT, Cursor, VS Code with GitHub Copilot, Windsurf, OpenAI Codex, Gemini CLI, Google Antigravity, OpenCode, OpenClaw, Command Code — and any other app that supports MCP over Streamable HTTP, including apps you use with DeepSeek or local models.

= What your AI can do on your site =

* **Posts, pages and custom post types** – create, edit, schedule, trash and restore, and roll back to earlier revisions.
* **Blocks and patterns** – write proper block-editor content and use your theme's ready-made layouts.
* **Media library** – upload images from a link, write alt text and captions, set featured images.
* **Categories, tags and custom taxonomies.**
* **Comments** – read, reply and moderate.
* **Menus and site settings**, plus a read-only view of users, plugins and themes (with Full control).
* **Site health** and, if you allow it, the PHP error log.

= Integrations with popular plugins =

Extra tools appear automatically when these plugins are active:

* **WooCommerce** – store overview, products (prices, stock, categories), orders, coupons and customers.
* **Yoast SEO and Rank Math** – SEO titles, meta descriptions, focus keywords and indexing, plus an SEO issue finder.
* **Advanced Custom Fields (ACF)** – see field groups and fill in custom fields.
* **Contact Form 7, WPForms and Gravity Forms** – find forms with their embed code and read submissions.
* **Any plugin that uses the WordPress Abilities API** – switch its abilities on under Tools.

= Ready-made tasks =

AI apps that support MCP prompts (such as Claude Desktop, Claude Code and VS Code) can offer one-click tasks: *Get to know my site*, *Write a blog post*, *Improve a page*, *SEO check-up*, *Fix missing image descriptions*, *Plan my content*, *Review new comments*, *Site health check*, *Store report*, *Add a product* and *Summarize form submissions*.

= Safe by default =

* **You choose the access** – Read only, Content editor or Full control, per app.
* **Drafts only** – new connections can write and edit drafts, but not publish or delete, until you allow it.
* **Undo anything** – every action is logged under Activity, and content changes can be undone with one click.
* **Trash, not delete** – deleted content goes to the trash. Permanent deletion is off unless you turn it on.
* **Protected by design** – AI apps cannot install, activate or deactivate plugins, switch themes, create or edit user accounts, or change your site address or admin email. Those stay in your hands in the WordPress dashboard.
* **Pause switch** – block every AI app instantly, and resume when you're ready.
* **Revoke any app** at any time; it's disconnected immediately.
* **Standards-based security** – OAuth 2.1 with PKCE for sign-in, and hashed connection keys and tokens.

= Also included =

* **Compact mode** for apps with tool limits (like Cursor): three tools that give access to everything.
* **Multisite support** – every site has its own connections and activity, and super admins can work on the content of any site in the network.
* **Connection check** that spots common hosting problems and explains how to fix them.
* **WP-CLI commands** – `wp viagent key-create`, `key-list`, `key-revoke`, `activity-list` and `activity-revert`.
* **Developer friendly** – built on the WordPress Abilities API, with filters to add tools, tasks and integrations.

== Installation ==

1. In your WordPress admin, go to **Plugins → Add New**, search for **Viagent**, then click **Install Now** and **Activate**.
2. You'll be taken to **Tools → Viagent**. Choose your AI app.
3. Choose what the app may do. **Write content** with **Drafts only** is a safe start.
4. Follow the steps shown for your app, then send the suggested first message. The app shows as **Connected** as soon as it works.

**Good to know:** Web apps such as Claude.ai and ChatGPT need your site to be online with HTTPS. Apps on your computer (Claude Desktop, Claude Code, Cursor, VS Code…) also work with local development sites.

== Frequently Asked Questions ==

= What is MCP? =

MCP (Model Context Protocol) is an open standard that lets AI apps use tools from other software. With Viagent, your WordPress site becomes one of those tools, so your AI can read and update your site when you ask it to.

= How do I connect Claude to WordPress? =

Go to **Tools → Viagent → Connect an app** and pick **Claude.ai** (a custom connector under Customize → Connectors, which works in the Claude web, desktop and mobile apps), **Claude Desktop** or **Claude Code**. Viagent shows the exact steps and the first message to send.

= How do I connect ChatGPT to WordPress? =

In ChatGPT on the web, turn on **Developer mode** (Settings → Security and login), then open **Plugins**, click **+** and choose **Create app** (not "Create plugin" or "Upload plugin"), and enter your site's connector URL. Then sign in to WordPress and approve. Viagent's connect wizard shows every step. Developer mode needs a Plus, Pro, Business, Enterprise or Education plan.

= Does it work with Cursor, VS Code and other coding tools? =

Yes. Cursor and VS Code have one-click install buttons, and Windsurf, Codex, Gemini CLI, Antigravity, OpenCode and others get copy-ready configuration.

= Is it safe to let AI change my site? =

Every app acts as the WordPress user who connected it and can never do more than that user. New connections are limited to drafts, deletions go to the trash, everything is logged, and content changes can be undone with one click. You can pause all AI access at any time.

= What should I say to my AI first? =

The connect wizard gives you a ready-made first message, for example: "Use the site tools to look at my WordPress site and give me a short overview." After that, just ask in your own words — "Write a blog post about our summer sale and save it as a draft."

= Does Viagent send my data to an AI company? =

No. Viagent never sends your content to an AI service. Your AI app connects to your site, and only after you create a connection or approve a sign-in. What the app does with the content it reads is covered by that app's own privacy policy.

= Does it work on local sites like WordPress Studio or LocalWP? =

Yes, with AI apps on the same computer. The wizard adjusts the setup automatically for local development certificates where needed.

= Can I control exactly which tools the AI can use? =

Yes. Under **Tools → Viagent → Tools** you can search all tools and switch any of them on or off, and each connection only sees the tools its access level allows.

= My AI app can't connect. What should I do? =

Open **Tools → Viagent → Connect an app**. Apps that are set up but not connected show a **Show me how to finish** button. The **Settings** screen runs a connection check that explains common hosting problems, such as servers that remove the Authorization header.

= Does it work on multisite? =

Yes. Network-activate Viagent to give every site its own connections and activity. Super admins also get tools to list sites and to work on the content of any site in the network.

= What happens to my data if I delete the plugin? =

Nothing is removed unless you turn on **Delete all Viagent data** under **Tools → Viagent → Settings**. Deactivating the plugin never deletes anything.

= Where is the source code of the admin screens? =

The readable JavaScript and SCSS source is included in the `src` folder. The compiled files in `build` are created with @wordpress/scripts. The full development setup is on GitHub at https://github.com/zakariabinsaifullah/viagent: clone it, then run `npm install && npm run build`.

== External services ==

Viagent does not send your site's content to AI providers or to its authors, and it has no tracking. It connects to external services only in these cases:

* **AI app sign-in details** (for example chatgpt.com) – when an AI app signs in with a Client ID Metadata Document, Viagent downloads that app's public client description from the web address the app provides (such as `https://chatgpt.com/oauth/…/client.json`) to check where to send you back after you approve. Only a normal download request is sent; no site data. The result is cached for an hour. See the privacy policy of the app you connect, for example [OpenAI's privacy policy](https://openai.com/policies/privacy-policy/).
* **Web addresses provided by your AI app** – when an AI app uploads an image or file from a link, Viagent downloads that file from the address the app gave. Only a normal download request is sent to that address. Local and private network addresses are blocked.

The connect wizard shows setup snippets and links to each AI app's own documentation (for example docs.anthropic.com, opencode.ai or docs.cursor.com). They are plain links: nothing is loaded from those sites unless you click one.

AI apps such as Claude, ChatGPT or Cursor connect to your site; your site does not connect to them. The content they read is handled under the privacy policy of the app you use.

== Changelog ==

= 1.0.1 =
* Renamed the plugin to Viagent.
* Removed tools that installed, activated or deactivated plugins, switched themes, created or edited users, or created network sites. Plugins, themes and users are now read-only.
* Tightened permissions: non-public post types and taxonomies are only listed for users who can edit them.
* The sign-in approval screen now loads its styles as a stylesheet.
* Fixed translations being loaded too early.

= 1.0.0 =
* Initial release.
* Native MCP server (Streamable HTTP) built on the WordPress Abilities API.
* Connect wizard for 14 AI apps, with one-click sign-in (OAuth 2.1 with PKCE, Client ID Metadata Documents and dynamic client registration) for Claude.ai and ChatGPT.
* Tools for posts, pages, media, taxonomies, comments, menus and settings, plus read-only users, plugins and themes.
* Integrations with WooCommerce, Yoast SEO, Rank Math, Advanced Custom Fields, Contact Form 7, WPForms and Gravity Forms.
* Ready-made tasks (MCP prompts), compact mode and multisite support.
* Safety controls: access levels, drafts-only mode, activity log with undo, and a pause switch.

== Upgrade Notice ==

= 1.0.1 =
Renamed to Viagent. Plugin, theme and user management tools were removed; those tasks stay in the WordPress dashboard.

= 1.0.0 =
Initial release of Viagent – connect WordPress to Claude, ChatGPT, Cursor and other AI agents.
