# WordPress OAuth Connect Integration Plan

This plan details the steps required to transition the WordPress plugin from manual credential entry to an automated "Connect via App" flow. The work is split across the Plugin, Web App, and Backend API.

## User Review Required
> [!IMPORTANT]
> Please review the phasing. Since you want to test the plugin changes first, the verification plan includes a strategy to mock the backend code exchange so you can validate the plugin's behavior in isolation before touching the Web App or Backend API.

## Proposed Changes

### 1. WordPress Plugin (`nxis-wp-plugin`)

**Phase 1: Admin UI Overhaul & State Management**
- Modify `nxis-ai.php` to check if `client_id`, `client_secret`, and `site_id` are already set in `nxis_ai_settings`.
- **State: Disconnected:** Hide the manual input fields. Show a prominent "Connect to Nxis" button. The button will redirect to the Web App's connection route (e.g., `https://app.nxis.io/integrations/wordpress/setup?return_url=[url_encoded_admin_url]`).
- **State: Connected:** Show a success state ("Connected to Nxis AI"). Display the `site_id` (read-only) and a "Disconnect" button. Disconnecting will clear the credentials from `nxis_ai_settings` and clear any cached SSR data.
- *Testing consideration:* Add a `WP_DEBUG` toggle or hidden feature to reveal the legacy manual input fields so you can continue testing SSR functionality independently while building the OAuth flow.

**Phase 2: Authorization Code Exchange Handler**
- Add a new function hooked to `admin_init` to intercept redirects back from the Web App.
- Check for `$_GET['page'] == 'nxis-ai'` and `isset($_GET['code'])`.
- If a `code` is present, make a `wp_remote_post` request to the Nxis API's code exchange endpoint (e.g., `https://api.nxis.ai/v1/oauth/exchange`) passing the `code`.
- If the API returns a `200 OK` with `client_id`, `client_secret`, and `site_id`, update `nxis_ai_settings` and redirect the user back to the settings page (stripping the `code` parameter from the URL to prevent re-submission).
- Handle error states gracefully (e.g., "Connection failed: Invalid authorization code").

---

### 2. Nxis Web App (`nxis-ui`)

**Phase 1: WordPress Callback Route Handler (`routing.js`)**
- Add a handler for the WordPress redirect in `src/routing/routing.js`.
- Inside the existing `callbackHandler` function (which handles paths like `/callback/:path*`), add a `case 'wordpress':`.
- Extract the `return_url` from the query parameters and store it in `sessionStorage` (e.g., `sessionStorage.setItem('wordpress_return_url', return_url)`).
- Intelligently redirect the user based on their session history (`DataStoreService.getHistory`). If they have an active `org_id` and `site_id`, redirect them directly to `/${org_id}/${site_id}/integrations?action=connect_wordpress`. Otherwise, redirect them to the root (`/`) to select an Organization and Site first.

**Phase 2: WordPress Integration UI (`integrations-view.js`)**
- Update `src/views/integrations-view.js` to display a new "WordPress" connection card alongside Discord, MCP, etc.
- In the `onBeforeEnter` or `firstUpdated` lifecycle methods, check for `action=connect_wordpress` in the URL and `sessionStorage.getItem('wordpress_return_url')`. If present, you can automatically prompt the user to confirm the connection or highlight the WordPress card.
- Create a `connectIntegration('WordPress')` handler:
  - Make a POST request to the Backend API to generate the `authorization_code` for the selected `site_id`.
  - Retrieve the `return_url` from `sessionStorage`.
  - Clear the `sessionStorage` item.
  - Redirect the user's browser back to `${return_url}?code=${authorization_code}`.

---

### 3. Nxis Backend API

**Phase 1: Authorization Code Generation Endpoint**
- Create endpoint: `POST /v1/integrations/wordpress/authorize`.
- **Auth:** Require valid Auth0 user token.
- **Logic:** Validate that the authenticated user has permission to manage the requested `site_id`.
- Generate a secure, random `authorization_code` (e.g., 32-character hex string).
- Store this code in a database or Redis cache with a short expiration (e.g., 5 minutes). Link the `code` to the `site_id`.
- Return the `authorization_code` to the Web App.

**Phase 2: Code Exchange & Credential Provisioning Endpoint**
- Create endpoint: `POST /v1/oauth/exchange`.
- **Auth:** Public/Unauthenticated (the `code` acts as a one-time bearer token).
- **Logic:** Validate the `code`. If valid, delete it immediately from the store to prevent reuse.
- Look up the `site_id` associated with the code.
- Generate a new OAuth Client ID and Client Secret specifically scoped to this `site_id`.
- Return `{ client_id, client_secret, site_id }` as a JSON response.

## Verification Plan

### Plugin-First Verification (Mocking the Backend)
Since you want to test the plugin first, we will build a testing harness:
1. Implement Phase 1 & 2 of the Plugin.
2. In the plugin code, temporarily point the code exchange request to a mock service (like a local script or Postman mock server) that simply returns a hardcoded `{ "client_id": "mock_id", "client_secret": "mock_secret", "site_id": "mock_site" }`.
3. Go to the WP Admin, click "Connect to Nxis" (which you can temporarily point to `http://localhost/dummy`). 
4. Manually navigate back to your WP admin URL and append `?code=test_123`.
5. Verify the plugin intercepts the code, "exchanges" it with the mock server, saves the credentials, and enters the "Connected" state.

### End-to-End Verification
Once the App and Backend are complete:
1. Start from a disconnected WordPress install.
2. Click "Connect to Nxis".
3. Verify smooth redirect to the Web App, Auth0 login step, and Org/Site selection UI.
4. Click Connect in the Web App.
5. Verify the redirect back to WordPress, automatic credential provisioning, and functional SSR data fetching.

---

## App-Originated Installation Flow

Currently, the flow assumes the user *starts* in WordPress. However, users will often discover the WordPress integration while browsing your Web App. 

Here is the best path to trigger the installation from within the `nxis-ui` app:

### The "App-to-WordPress" Bridge
When a user clicks "WordPress" on the Integrations page in `nxis-ui`, we present a modal with two paths based on whether they already have the plugin.

**Path A: Already Installed**
- We show a simple instruction: *"Go to your WordPress Admin panel -> Settings -> Nxis AI and click **Connect to Nxis**."*

**Path B: Needs Installation**
We can offer two seamless ways to install the plugin depending on whether it is published on the WordPress.org repository.

1. **Remote Search Redirect (If published on WP.org)**
   - Prompt the user for their WordPress Site URL (e.g., `https://myblog.com`).
   - Generate a "Magic Link" that redirects them directly to the plugin search page inside their own WP admin:
     `https://{their-url}/wp-admin/plugin-install.php?s=Nxis+AI&tab=search&type=term`
   - They click the link, log into WP, click "Install", then "Activate", and are immediately dropped into your plugin where they click "Connect" to finish the loop.

2. **Zip Upload Redirect (If un-published/private)**
   - Provide a prominent **Download Plugin .zip** button in the Web App modal.
   - Prompt them for their WordPress Site URL.
   - Generate a link to their plugin upload page:
     `https://{their-url}/wp-admin/plugin-install.php?tab=upload`
   - Show a quick 3-step GIF on how to upload the `.zip`.

By building this bridge, you don't need to create a completely separate OAuth flow for "App-originated" connections. You simply help them install the plugin, and then the plugin's "Connect" button kicks off the exact same robust OAuth flow we've already designed!
