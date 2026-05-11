# Nxis AI Integration for WordPress

## Overview
This plugin connects your WordPress site to the Nxis AI platform, allowing you to dynamically inject JSON-LD structured data into your web pages. By embedding this structured data, the Nxis AI plugin helps transform your standard content into semantic data that is easily discoverable and consumable across the modern search and AI ecosystem (including Search Engines, AI Agents, and Large Language Models).

The plugin supports two modes of operation:
1. **Client-Side (Browser Framework)**
2. **Server-Side Rendering (SSR)**

## Features

- **Easy Setup**: No coding required. Simply input your Nxis JWT Token.
- **Two Injection Methods**: Choose between lightweight client-side JavaScript injection or robust Server-Side Rendering (SSR).
- **SSR Caching**: Built-in caching for SSR mode leveraging the WordPress Transient API to preserve your site's speed and respect API rate limits.
- **Bot Detection**: Passes the User-Agent to the Nxis Proxy when using SSR to ensure search engine and LLM bots see your structured data correctly.

## How It Works

This plugin replicates the functionality of the `nxis-js` library directly within WordPress.

### Client-Side (Browser)
If "Client-side" is selected, the plugin injects a `<link rel="preconnect">` for the Nxis CDN and an asynchronous script tag (`<script async ...>`). When your visitors load the page, their browser requests the JSON-LD using your secure JWT token and injects it directly into the Document Object Model (DOM).

### Server-Side Rendering (SSR) - *Recommended*
If "Server-side Rendering (SSR)" is selected, the plugin intercepts the WordPress page rendering process. Before the page is sent to the visitor, the plugin server securely connects to the Nxis AI Proxy (authenticated via your **Client ID** and **Client Secret**) and fetches the JSON-LD data. The data is then injected directly into your HTML's `<head>`.

**Why SSR is better**: Large Language Models (LLMs) and intelligent agents do not always render or execute Javascript. If they crawl your page and you're using Client-Side injection, they might miss your structured data entirely. SSR guarantees that your semantic JSON-LD data is immediately visible in the source HTML.

## Installation

### Manual Installation
1. Download a zip wrapper of this repository (or clone it).
2. Compress the contents into a file named `nxis-ai.zip`.
3. In your WordPress admin dashboard, navigate to **Plugins > Add New > Upload Plugin**.
4. Choose the `nxis-ai.zip` file, click **Install Now**, and then **Activate**.

### Development Installation
1. Move or clone this folder directly into your `wp-content/plugins/` directory.
   ```bash
   cd wp-content/plugins
   git clone <repository_url> nxis-wp-plugin
   ```
2. Navigate to your WordPress Plugins dashboard and **Activate** the "Nxis AI" plugin.

## Configuration

1. Once the plugin is authenticated, a new menu item will appear under **Settings > Nxis AI**.
2. **Public JWT Token**: Used for **Client-side** injection.
3. **Client ID / Client Secret**: Used for **Server-side Rendering**.
4. **Injection Method**: Select between "Client-side" and "Server-side Rendering (SSR)".
   - *If using SSR*, define the **SSR Cache (Hours)** setting (default is 24 hours). This determines how long your WordPress site remembers the structured data for a specific page without needing to re-fetch from Nxis AI infrastructure, ensuring optimal site performance.
5. Click **Save Changes**.

## Caching and Updates
When using SSR, WordPress caches responses locally behind-the-scenes using Transients. When you save your Nxis AI settings, it automatically clears the structured data cache for your site.

## Requirements
- PHP 7.4 or later (8.0+ recommended)
- WordPress 5.8 or later
- Active membership/API Access with Nxis AI

## Support
For issues relating to JWT Tokens, API quotas, or structured data format, please open a support ticket from your Nxis AI Dashboard.
