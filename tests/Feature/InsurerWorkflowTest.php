<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InsurerWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function createValidatedPolicy(): PolicyRequest
    {
        return PolicyRequest::create([
            'client_request_id' => 'REQ-TEST-' . uniqid(),
            'agent_id' => 'AGENT-01',
            'customer_name' => 'Charlie Brown',
            'date_of_birth' => '1992-03-15',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
            'premium' => 60.00,
            'status' => 'VALIDATED',
        ]);
    }

    public function test_submit_transitions_request_to_submitted(): void
    {
        $policy = $this->createValidatedPolicy();

        $response = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Policy submitted to insurer successfully',
                'data' => [
                    'id' => $policy->id,
                    'status' => 'SUBMITTED',
                    'attempt_count' => 1,
                ],
            ]);

        $this->assertNotNull($response->json('data.provider_reference'));

        $this->assertDatabaseHas('policy_requests', [
            'id' => $policy->id,
            'status' => 'SUBMITTED',
            'attempt_count' => 1,
        ]);
    }

    public function test_cannot_resubmit_already_issued_policy(): void
    {
        $policy = $this->createValidatedPolicy();
        $policy->update([
            'status' => 'ISSUED',
            'policy_number' => 'POL-ALREADY-ISSUED',
        ]);

        $response = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $response->assertStatus(400)
            ->assertJson([
                'message' => 'Policy has already been issued and cannot be re-submitted',
            ]);
    }

    public function test_callback_issues_policy_successfully(): void
    {
        $policy = $this->createValidatedPolicy();
        $policy->update([
            'status' => 'SUBMITTED',
            'provider_reference' => 'INS-REF-12345',
            'attempt_count' => 1,
        ]);

        $callbackPayload = [
            'provider_reference' => 'INS-REF-12345',
            'status' => 'ISSUED',
            'policy_number' => 'POL-2026-9999',
            'message' => 'Policy successfully issued by insurer',
        ];

        $response = $this->postJson('/api/mock-insurer/callback', $callbackPayload);

        $response->assertStatus(200)
            ->assertJson([
                'message' => 'Insurer callback processed successfully',
                'data' => [
                    'id' => $policy->id,
                    'status' => 'ISSUED',
                    'policy_number' => 'POL-2026-9999',
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'id' => $policy->id,
            'status' => 'ISSUED',
            'policy_number' => 'POL-2026-9999',
        ]);
    }

    public function test_reliability_scenario_1_temporary_insurer_failure_and_retry(): void
    {
        $policy = $this->createValidatedPolicy();

        // 1. Initial attempt fails with temporary insurer failure (503)
        $failResponse = $this->postJson("/api/policy-requests/{$policy->id}/submit", [
            'simulation' => 'temporary_failure',
        ]);

        $failResponse->assertStatus(503)
            ->assertJson([
                'data' => [
                    'status' => 'FAILED',
                    'attempt_count' => 1,
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'id' => $policy->id,
            'status' => 'FAILED',
            'attempt_count' => 1,
        ]);

        // 2. Retry attempt succeeds
        $retryResponse = $this->postJson("/api/policy-requests/{$policy->id}/submit");

        $retryResponse->assertStatus(200)
            ->assertJson([
                'data' => [
                    'status' => 'SUBMITTED',
                    'attempt_count' => 2,
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'id' => $policy->id,
            'status' => 'SUBMITTED',
            'attempt_count' => 2,
        ]);
    }

    public function test_reliability_scenario_2_timeout_and_late_callback_recovery(): void
    {
        $policy = $this->createValidatedPolicy();

        // 1. Client experiences timeout during submit, but insurer assigned provider reference
        $timeoutResponse = $this->postJson("/api/policy-requests/{$policy->id}/submit", [
            'simulation' => 'timeout',
        ]);

        $timeoutResponse->assertStatus(504);

        $policy->refresh();
        $this->assertNotNull($policy->provider_reference);
        $providerRef = $policy->provider_reference;

        // 2. Insurer issues policy asynchronously and fires webhook callback
        $callbackPayload = [
            'provider_reference' => $providerRef,
            'status' => 'ISSUED',
            'policy_number' => 'POL-TIMEOUT-RESOLVED',
            'message' => 'Policy issued following timeout reconciliation',
        ];

        $callbackResponse = $this->postJson('/api/mock-insurer/callback', $callbackPayload);

        $callbackResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Insurer callback processed successfully',
                'data' => [
                    'id' => $policy->id,
                    'status' => 'ISSUED',
                    'policy_number' => 'POL-TIMEOUT-RESOLVED',
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'id' => $policy->id,
            'status' => 'ISSUED',
            'policy_number' => 'POL-TIMEOUT-RESOLVED',
        ]);
    }

    public function test_reliability_scenario_3_duplicate_callbacks_handled_safely(): void
    {
        $policy = $this->createValidatedPolicy();
        $policy->update([
            'status' => 'SUBMITTED',
            'provider_reference' => 'INS-DUP-TEST',
            'attempt_count' => 1,
        ]);

        $payload = [
            'provider_reference' => 'INS-DUP-TEST',
            'status' => 'ISSUED',
            'policy_number' => 'POL-DUP-111',
            'message' => 'Policy issued',
        ];

        // First callback execution
        $first = $this->postJson('/api/mock-insurer/callback', $payload);
        $first->assertStatus(200)
            ->assertJson([
                'message' => 'Insurer callback processed successfully',
                'data' => [
                    'status' => 'ISSUED',
                    'policy_number' => 'POL-DUP-111',
                ],
            ]);

        // Duplicate callback execution
        $duplicate = $this->postJson('/api/mock-insurer/callback', $payload);
        $duplicate->assertStatus(200)
            ->assertJson([
                'message' => 'Callback already processed (duplicate callback ignored)',
                'data' => [
                    'status' => 'ISSUED',
                    'policy_number' => 'POL-DUP-111',
                ],
            ]);

        // Make sure no extra records or corruption occurred
        $this->assertEquals(1, PolicyRequest::where('policy_number', 'POL-DUP-111')->count());
    }

    public function test_callback_fails_with_invalid_provider_reference(): void
    {
        $response = $this->postJson('/api/mock-insurer/callback', [
            'provider_reference' => 'INS-NONEXISTENT',
            'status' => 'ISSUED',
            'policy_number' => 'POL-GHOST',
        ]);

        $response->assertStatus(404)
            ->assertJson([
                'message' => 'Policy request not found for provider reference: INS-NONEXISTENT',
            ]);
    }

    public function test_provider_status_lookup(): void
    {
        $response = $this->getJson('/api/mock-insurer/status/INS-STATUS-REF-1');
        $response->assertStatus(200)
            ->assertJson([
                'provider_reference' => 'INS-STATUS-REF-1',
                'status' => 'ISSUED',
            ]);
    }

    public function test_reconciliation_resolves_timeout_after_issuance(): void
    {
        $policy = $this->createValidatedPolicy();

        // 1. Client experiences timeout during submit, provider_reference created
        $this->postJson("/api/policy-requests/{$policy->id}/submit", [
            'simulation' => 'timeout',
        ]);

        $policy->refresh();
        $this->assertNotNull($policy->provider_reference);

        // 2. Perform reconciliation instead of re-submitting blind
        $reconResponse = $this->postJson("/api/policy-requests/{$policy->id}/reconcile");
        $reconResponse->assertStatus(200)
            ->assertJson([
                'message' => 'Policy reconciliation successful',
                'data' => [
                    'id' => $policy->id,
                    'status' => 'ISSUED',
                ],
            ]);

        $policy->refresh();
        $this->assertEquals('ISSUED', $policy->status);
        $this->assertNotNull($policy->policy_number);
    }
}
