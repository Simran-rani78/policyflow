# AI Usage Documentation — PolicyFlow

This document discloses the use of Artificial Intelligence (AI) tools during the design, implementation, and verification of the **PolicyFlow** travel insurance policy issuance and reconciliation API, strictly following Section 10 of the placement assessment guidelines.

---

## 1. Tools Used
- **AI Assistant**: Google DeepMind Antigravity (Powered by Gemini 3.8 Flash)
- **Primary Use Cases**: Accelerated boilerplate generation, code exploration, automated test generation, and debugging assistance.

---

## 2. Concrete Ways AI Helped

1. **Test Suite Generation for Complex Pricing Combinations**:
   AI assisted in generating extensive parameterized test fixtures for the dynamic premium formula ($\text{base rate} \times \text{travel days} \times \text{age multiplier} \times \text{destination multiplier} \times \text{coverage multiplier}$), verifying boundaries across youth ($\le 17$), adults ($18-60$), seniors ($61-70$), and elders ($71+$), as well as domestic vs. international destination tiers.

2. **Fault-Tolerance & Reliability Architecture**:
   AI helped structure the state transition flow and mock insurer simulation strategy:
   - Simulating HTTP 503 downstream temporary errors with attempt count increments.
   - Preserving `provider_reference` across simulated HTTP 504 timeouts to enable asynchronous callback and reconciliation recovery.
   - Safe idempotency handling for duplicate callbacks on confirmed `ISSUED` policies.

3. **Database Migration to MySQL**:
   AI helped review environment variable configuration and migration scripts to transition the project smoothly from SQLite to MySQL under XAMPP.

---

## 3. Incorrect or Incomplete AI Suggestions Encountered

During the early development phase, AI output had two notable omissions/defects that required human diagnosis and intervention:

1. **Missing Mass-Assignment Configuration (`$fillable`)**:
   - *Issue*: AI initially drafted the `PolicyRequest` model class with an empty body (`class PolicyRequest extends Model { }`). When calling `PolicyRequest::create($data)` in the controller, Laravel immediately threw an unhandled `Illuminate\Database\Eloquent\MassAssignmentException`, resulting in an unexpected HTTP 500 error.
   - *Fix*: Diagnosed the error via `storage/logs/laravel.log` and explicitly configured the `$fillable` array alongside appropriate attribute type casts (`date`, `integer`, `decimal:2`).

2. **PHP Namespace Syntax Error from Leading Whitespace**:
   - *Issue*: The controller file was initially generated with an empty newline prior to the opening `<?php` tag on line 1. In PHP, any character or whitespace outside `<?php` causes output buffering to start, violating the requirement that `namespace App\Http\Controllers\Api;` must be the script's very first statement. This generated a `Symfony\Component\ErrorHandler\Error\FatalError`.
   - *Fix*: Inspected the raw file, eliminated the leading whitespace, and validated all files with `php -l` before execution.

---

## 4. How the Output Was Tested and Verified

1. **Automated Unit & Feature Testing**:
   - Developed a 17-test feature suite using PHPUnit covering input validation, pricing rules, agent/client request idempotency, insurer submissions, timeouts, and duplicate callbacks.
   - Ran `php vendor/phpunit/phpunit/phpunit`: all **17 tests passed with 77 assertions (100% success rate)**.

2. **Database Integrity & Concurrency Verification**:
   - Ran database migrations on MySQL (`policyflow` database) and verified compound unique constraints on `(agent_id, client_request_id)`.
   - Tested duplicate concurrent and serial submissions to ensure no redundant rows were created.

3. **End-to-End API Execution**:
   - Dispatched real HTTP requests using curl scripts to verify response status codes:
     - `201 Created` on new intake
     - `200 OK` on duplicate intake
     - `422 Unprocessable Content` on validation failure
     - `503 Service Unavailable` on simulated provider outage
     - `200 OK` on safe replay of webhook callbacks
