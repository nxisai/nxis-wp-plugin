# Step 1 — Get an access token

curl -s -X POST https://authorization.nxis.ai/v1/oauth/token \
 -H "Content-Type: application/x-www-form-urlencoded" \
 -d "grant_type=client_credentials" \
 -d "client_id=${NXIS_CLIENT_ID}" \
  -d "client_secret=${NXIS_CLIENT_SECRET}" \
 -d "scope=jsonld:read"

# Step 2 — Fetch structured data

curl -s "https://api.nxis.ai/v1/nxis:ssr?uri=https://yoursite.com/page&site_id=${NXIS_SITE_ID}" \
 -H "Authorization: Bearer ${ACCESS_TOKEN}" \
 -H "User-Agent: Googlebot/2.1"

# Combined one-liner

ACCESS_TOKEN=$(curl -s -X POST https://authorization.nxis.ai/v1/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=client_credentials" \
  -d "client_id=${NXIS_CLIENT_ID}" \
 -d "client_secret=${NXIS_CLIENT_SECRET}" \
  -d "scope=jsonld:read" | jq -r '.access_token') && \
curl -s "https://api.nxis.ai/v1/nxis:ssr?uri=https://yoursite.com/page&site_id=${NXIS_SITE_ID}" \
 -H "Authorization: Bearer $ACCESS_TOKEN" | jq '.data'
