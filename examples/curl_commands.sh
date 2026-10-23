#!/usr/bin/env bash

# PolicyFlow API curl Examples

BASE_URL="http://127.0.0.1:8000/api"

echo "=== 1. Create a Policy Request (Sample from Assignment Spec) ==="
curl -X POST "$BASE_URL/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
echo -e "\n"

echo "=== 2. Replay Identical Request (Idempotency - Expect 200 OK) ==="
curl -X POST "$BASE_URL/policy-requests" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "client_request_id": "AGT-2026-0001",
    "agent_id": "AGENT-17",
    "customer_name": "Sample Customer",
    "date_of_birth": "1998-04-10",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-10",
    "trip_end": "2026-11-14",
    "plan_code": "PLUS",
    "sum_insured": 300000
  }'
echo -e "\n"

echo "=== 3. Retrieve Policy Request by ID ==="
curl -X GET "$BASE_URL/policy-requests/1" \
  -H "Accept: application/json"
echo -e "\n"

echo "=== 4. Submit Policy Request to Insurer ==="
curl -X POST "$BASE_URL/policy-requests/1/submit" \
  -H "Accept: application/json"
echo -e "\n"

echo "=== 5. Simulate Temporary Failure (503) ==="
curl -X POST "$BASE_URL/policy-requests/1/submit" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{"simulation": "temporary_failure"}'
echo -e "\n"

echo "=== 6. Retry Submission After Failure ==="
curl -X POST "$BASE_URL/policy-requests/1/submit" \
  -H "Accept: application/json"
echo -e "\n"

echo "=== 7. Webhook Callback from Mock Insurer ==="
curl -X POST "$BASE_URL/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_reference": "INS-SAMPLE-REF",
    "status": "ISSUED",
    "policy_number": "POL-2026-98765",
    "message": "Policy issued"
  }'
echo -e "\n"

echo "=== 8. Duplicate Webhook Callback (Idempotent 200 OK) ==="
curl -X POST "$BASE_URL/mock-insurer/callback" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json" \
  -d '{
    "provider_reference": "INS-SAMPLE-REF",
    "status": "ISSUED",
    "policy_number": "POL-2026-98765",
    "message": "Policy issued"
  }'
echo -e "\n"
