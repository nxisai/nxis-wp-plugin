#!/bin/bash

# Configuration details
# NXIS_CLIENT_ID="nxis_cid_Hpb23EnZ3W2HlrY2"
# NXIS_CLIENT_SECRET="nxis_sec_Xv25Ip0iJFnTtVcZFBolzsIHDe0alYdc"
# NXIS_SITE_ID="69eace2e8331f533b4358a6f"
# PAGE_URI="https://arksers.space/nora"

NXIS_CLIENT_ID=nxis_cid_SX9zliH31eXAgB0c
NXIS_CLIENT_SECRET=nxis_sec_11vY8gnpjm0T1KUuyMxk8DZcfdIcfYNy
NXIS_SITE_ID="69f3be9a72eb54b68301350e"
PAGE_URI="http://arksers.space"

echo "--------------------------------------------------------"
echo "Step 1: Requesting OAuth Access Token using Client Credentials"
echo "--------------------------------------------------------"

# Fetch Token
OUTPUT=$(curl -s -X POST https://authorization.nxis.ai/v1/oauth/token \
  -H "Content-Type: application/x-www-form-urlencoded" \
  -d "grant_type=client_credentials" \
  -d "client_id=${NXIS_CLIENT_ID}" \
  -d "client_secret=${NXIS_CLIENT_SECRET}" \
  -d "scope=jsonld:read")

# Extract Token
ACCESS_TOKEN=$(echo "$OUTPUT" | grep -o '"access_token": "[^"]*' | grep -o '[^"]*$')


# Fallback checking
if [ -z "$ACCESS_TOKEN" ] || [ "$ACCESS_TOKEN" == "null" ]; then
    echo "Error: Failed to retrieve Access Token. Output was:"
    echo "$OUTPUT"
    exit 1
fi

echo "Success! Token retrieved."
echo "$ACCESS_TOKEN"

echo "--------------------------------------------------------"
echo "Step 2: Testing SSR API Data Fetch"
echo "--------------------------------------------------------"
echo "Sending GET request to https://api.nxis.ai/v1/nxis:ssr..."

# Fetch the SSR data including the headers to see exactly what API returns
curl -s -w "\nHTTP Status Code: %{http_code}\n" "https://api.nxis.ai/v1/nxis:ssr?uri=${PAGE_URI}&site_id=${NXIS_SITE_ID}" \
 -H "Authorization: Bearer ${ACCESS_TOKEN}" 

echo "--------------------------------------------------------"
