# PolicyFlow — Travel Insurance Policy Issuance & Reconciliation API

PolicyFlow is an enterprise-grade RESTful API built for the **Trippers Shield** travel insurance platform. It manages the full lifecycle of travel insurance requests: customer validation, dynamic premium computation, idempotent policy request creation, external insurer submission, and resilient webhook callback reconciliation.

---

## Architecture Overview

```
                                  Client / Agent
                                        |
                 +----------------------+----------------------+
                 | (1. POST /api/policy-requests)              |
                 v                                             v
        +------------------+                        +--------------------+
        | Validation &     |                        | State Transitions  |
        | Premium Engine   |                        | & Attempt Tracking |
        +------------------+                        +--------------------+
                 |                                             |
                 +----------------------+----------------------+
                                        |
                                        v
                            +-----------------------+
                            | MySQL Database        |
                            | (Unique: agent_id +   |
                            |  client_request_id)   |
                            +-----------------------+
                                        |
                                        v
                 (2. POST /api/policy-requests/{id}/submit)
                                        |
                                        v
                             +---------------------+
                             | Mock Insurer Service|
                             +---------------------+
                                        |
                     +------------------+------------------+
                     | (Sync response                      |
                     |  or network failure)                v
                     |                         (3. POST /api/mock-insurer/callback)
                     v                                     |
              Status: SUBMITTED or FAILED                  v
                     |                              Status: ISSUED
                     +------------------------------------>+
```

### State Machine Lifecycle
```
[RECEIVED] -> [VALIDATED] -> [SUBMITTED] -> [ISSUED]
                   |              |
                   v              v
               [FAILED] <---------+ (Retryable via /submit)
```

1. **`VALIDATED`**: The request payload passed all validation rules, premium has been calculated, and the record is stored idempotently.
2. **`SUBMITTED`**: Successfully dispatched to insurer; awaiting asynchronous underwriting callback.
3. **`ISSUED`**: Insurer has confirmed issuance and provided an official `policy_number`.
4. **`FAILED`**: Encountered a downstream failure, timeout, or insurer rejection. Can be retried via re-submission.

---

## Features & Reliability Guarantees

### 1. Request Idempotency
- **Unique Constraint**: The database enforces a compound unique constraint on `(agent_id, client_request_id)`.
- **Safe Replay**: Duplicate client requests with the same `agent_id` and `client_request_id` do not create duplicate database rows. The API safely intercepts the collision and returns the existing resource with HTTP `200 OK`.

### 2. Premium Computation Engine
The premium is calculated dynamically using deterministic business rules:
$$\text{Premium} = \text{round}(\text{BaseRate} \times \text{TripDays} \times \text{AgeMultiplier} \times \text{DestinationMultiplier} \times \text{CoverageMultiplier}, 2)$$

- **Base Rates per Day**:
  - `BASIC`: \$12.00 / day
  - `PLUS`: \$20.00 / day
  - `PREMIUM`: \$30.00 / day
- **Age Multipliers**:
  - $\le 17$ years: `1.10x`
  - $18 - 60$ years: `1.00x`
  - $61 - 70$ years: `1.50x`
  - $> 70$ years: `2.00x`
- **Destination Multipliers**:
  - `DOMESTIC`: `1.00x`
  - `INTERNATIONAL`: `1.80x`
- **Coverage (Sum Insured) Multipliers**:
  - \$100,000: `1.00x`
  - \$300,000: `1.25x`
  - \$500,000: `1.50x`

### 3. Reliability & Fault Tolerance Scenarios

- **Scenario 1: Temporary Insurer Failure & Retry**
  - If the downstream insurer returns an intermittent error (e.g., HTTP 503 Service Unavailable), PolicyFlow marks the policy request status as `FAILED`, preserves the failure reason in `last_response`, and increments `attempt_count`.
  - The client or agent can re-invoke `POST /api/policy-requests/{id}/submit`. The retry attempt increments `attempt_count` to 2 and successfully transitions to `SUBMITTED`.
- **Scenario 2: Network Timeout & Asynchronous Callback Reconciliation**
  - If the client submission request experiences a network timeout (HTTP 504) after the insurer has already generated a provider reference, PolicyFlow retains the `provider_reference`.
  - When the insurer delivers the issuance confirmation asynchronously via `POST /api/mock-insurer/callback`, the system matches the `provider_reference`, transitions the status to `ISSUED`, and records the `policy_number`.
- **Scenario 3: Duplicate Webhook Callback Handling**
  - Insurers may deliver webhook callbacks more than once due to network retries.
  - PolicyFlow checks if the policy request is already `ISSUED`. Subsequent callbacks return HTTP `200 OK` with an acknowledgment (`"Callback already processed"`), avoiding duplicate key violations or state corruption.

---

## API Endpoints

### 1. Create Policy Request
`POST /api/policy-requests`

**Request Headers**:
`Content-Type: application/json`, `Accept: application/json`

**Request Body**:
```json
{
  "client_request_id": "REQ-2026-001",
  "agent_id": "AGENT-007",
  "customer_name": "Jane Doe",
  "date_of_birth": "1994-06-15",
  "destination": "INTERNATIONAL",
  "trip_start": "2026-11-01",
  "trip_end": "2026-11-10",
  "plan_code": "PLUS",
  "sum_insured": 300000
}
```

**Response (201 Created)**:
```json
{
  "message": "Policy request created",
  "data": {
    "id": 1,
    "client_request_id": "REQ-2026-001",
    "agent_id": "AGENT-007",
    "customer_name": "Jane Doe",
    "date_of_birth": "1994-06-15T00:00:00.000000Z",
    "destination": "INTERNATIONAL",
    "trip_start": "2026-11-01T00:00:00.000000Z",
    "trip_end": "2026-11-10T00:00:00.000000Z",
    "plan_code": "PLUS",
    "sum_insured": 300000,
    "premium": "450.00",
    "status": "VALIDATED",
    "attempt_count": 0,
    "created_at": "2026-10-23T17:26:11.000000Z",
    "updated_at": "2026-10-23T17:26:11.000000Z"
  }
}
```

---

### 2. List Policy Requests
`GET /api/policy-requests?status=VALIDATED&page=1`

**Response (200 OK)**:
```json
{
  "current_page": 1,
  "data": [ ... ],
  "total": 1,
  "per_page": 10
}
```

---

### 3. Retrieve Single Policy Request
`GET /api/policy-requests/{id}`

**Response (200 OK)**:
Returns the complete policy request record matching the ID.

---

### 4. Submit to Insurer
`POST /api/policy-requests/{id}/submit`

**Optional Simulation Flags** (via request body or `X-Simulation` header):
- `temporary_failure`: Simulates a 503 downstream insurer error.
- `timeout`: Simulates an HTTP 504 timeout during dispatch.
- `immediate_issue`: Simulates instant synchronous policy issuance.

**Response (200 OK)**:
```json
{
  "message": "Policy submitted to insurer successfully",
  "data": {
    "id": 1,
    "status": "SUBMITTED",
    "provider_reference": "INS-ABC123XYZ",
    "attempt_count": 1,
    "last_response": "{\"provider_reference\":\"INS-ABC123XYZ\",\"status\":\"SUBMITTED\"}"
  }
}
```

---

### 5. Mock Insurer Callback (Webhook)
`POST /api/mock-insurer/callback`

**Request Body**:
```json
{
  "provider_reference": "INS-ABC123XYZ",
  "status": "ISSUED",
  "policy_number": "POL-20261023-XYZ890",
  "message": "Policy successfully issued"
}
```

**Response (200 OK)**:
```json
{
  "message": "Insurer callback processed successfully",
  "data": {
    "id": 1,
    "status": "ISSUED",
    "policy_number": "POL-20261023-XYZ890"
  }
}
```

---

### 6. Provider Status Lookup (Reconciliation)
`GET /api/mock-insurer/status/{provider_reference}`

Queries the mock insurer to inspect the external status of a policy when a timeout occurs.

---

### 7. Reconcile Policy Request
`POST /api/policy-requests/{id}/reconcile`

Reconciles a timed-out policy with the insurer records without blindly creating duplicate submissions.

---

## Example Requests & Tooling
Pre-configured API requests are provided in the `examples/` directory:
- `examples/requests.http`: Directly runnable inside VS Code (REST Client), PhpStorm, or Postman.
- `examples/curl_commands.sh`: Executable bash script testing each endpoint sequentially.

---

## Installation & Setup

### Prerequisites
- **PHP 8.2+** with extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `curl`
- **Composer 2.x**
- **MySQL 8.0+** or **MariaDB 10.4+**

### Step-by-Step Setup
1. **Clone / Navigate to Repository**:
   ```bash
   cd C:\Users\sahil\policyflow
   ```

2. **Install PHP Dependencies**:
   ```bash
   composer install
   ```

3. **Configure Environment**:
   Copy `.env.example` to `.env` (or verify existing `.env`):
   ```ini
   DB_CONNECTION=mysql
   DB_HOST=127.0.0.1
   DB_PORT=3306
   DB_DATABASE=policyflow
   DB_USERNAME=root
   DB_PASSWORD=
   ```

4. **Create Database & Run Migrations**:
   ```bash
   # Ensure MySQL service is running, then run:
   php artisan migrate:fresh --force
   ```

5. **Start Development Server**:
   ```bash
   php artisan serve
   ```
   The API will be available at `http://127.0.0.1:8000`.

---

## Running Automated Tests

PolicyFlow features a test suite covering validation, dynamic premium math, idempotency, insurer workflows, and reliability scenarios:

```bash
php vendor/phpunit/phpunit/phpunit
```
or
```bash
php artisan test
```

### Test Coverage Highlights
- **17 Tests, 77 Assertions, 100% Passing**:
  - `PolicyRequestTest`:
    - `test_cannot_create_policy_request_with_invalid_data`
    - `test_cannot_create_policy_request_with_invalid_dates_or_plans`
    - `test_can_create_policy_request_successfully`
    - `test_calculates_correct_premium_for_various_combinations` (including exact spec example)
    - `test_idempotent_duplicate_request_returns_existing`
    - `test_list_and_retrieve_policy_requests`
  - `InsurerWorkflowTest`:
    - `test_submit_transitions_request_to_submitted`
    - `test_cannot_resubmit_already_issued_policy`
    - `test_callback_issues_policy_successfully`
    - `test_reliability_scenario_1_temporary_insurer_failure_and_retry`
    - `test_reliability_scenario_2_timeout_and_late_callback_recovery`
    - `test_reliability_scenario_3_duplicate_callbacks_handled_safely`
    - `test_callback_fails_with_invalid_provider_reference`
    - `test_provider_status_lookup`
    - `test_reconciliation_resolves_timeout_after_issuance`
