<?php

namespace Tests\Feature;

use App\Models\PolicyRequest;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PolicyRequestTest extends TestCase
{
    use RefreshDatabase;

    public function test_cannot_create_policy_request_with_invalid_data(): void
    {
        $response = $this->postJson('/api/policy-requests', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'client_request_id',
                'agent_id',
                'customer_name',
                'date_of_birth',
                'destination',
                'trip_start',
                'trip_end',
                'plan_code',
                'sum_insured',
            ]);
    }

    public function test_cannot_create_policy_request_with_invalid_dates_or_plans(): void
    {
        $response = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-INV-1',
            'agent_id' => 'AGENT-1',
            'customer_name' => 'Jane Doe',
            'date_of_birth' => Carbon::tomorrow()->toDateString(), // Future DOB invalid
            'destination' => 'MARS', // Invalid destination
            'trip_start' => '2026-12-10',
            'trip_end' => '2026-12-01', // End before start
            'plan_code' => 'ULTRA', // Invalid plan
            'sum_insured' => 200000, // Invalid sum insured tier
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'date_of_birth',
                'destination',
                'trip_end',
                'plan_code',
                'sum_insured',
            ]);
    }

    public function test_can_create_policy_request_successfully(): void
    {
        $payload = [
            'client_request_id' => 'REQ-001',
            'agent_id' => 'AGENT-101',
            'customer_name' => 'Alice Smith',
            'date_of_birth' => '1995-04-12',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-10',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ];

        $response = $this->postJson('/api/policy-requests', $payload);

        $response->assertStatus(201)
            ->assertJson([
                'message' => 'Policy request created',
                'data' => [
                    'client_request_id' => 'REQ-001',
                    'agent_id' => 'AGENT-101',
                    'customer_name' => 'Alice Smith',
                    'status' => 'VALIDATED',
                    'premium' => '120.00',
                ],
            ]);

        $this->assertDatabaseHas('policy_requests', [
            'client_request_id' => 'REQ-001',
            'agent_id' => 'AGENT-101',
            'status' => 'VALIDATED',
        ]);
    }

    public function test_calculates_correct_premium_for_various_combinations(): void
    {
        // Case 1: BASIC (12), DOMESTIC (1.0), 100k (1.0), 25yo (1.0), 10 days = 120.00
        $res1 = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-CALC-1',
            'agent_id' => 'AGENT-CALC',
            'customer_name' => 'Adult Domestic',
            'date_of_birth' => Carbon::now()->subYears(25)->toDateString(),
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-10',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $res1->assertStatus(201);
        $this->assertEquals('120.00', $res1->json('data.premium'));

        // Case 2: PLUS (20), INTERNATIONAL (1.80), 300k (1.25), 16yo (1.10), 5 days
        // 20 * 5 * 1.10 * 1.80 * 1.25 = 247.50
        $res2 = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-CALC-2',
            'agent_id' => 'AGENT-CALC',
            'customer_name' => 'Youth International',
            'date_of_birth' => Carbon::now()->subYears(16)->toDateString(),
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);
        $res2->assertStatus(201);
        $this->assertEquals('247.50', $res2->json('data.premium'));

        // Case 3: PREMIUM (30), INTERNATIONAL (1.80), 500k (1.50), 65yo (1.50), 7 days
        // 30 * 7 * 1.50 * 1.80 * 1.50 = 850.50
        $res3 = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-CALC-3',
            'agent_id' => 'AGENT-CALC',
            'customer_name' => 'Senior International',
            'date_of_birth' => Carbon::now()->subYears(65)->toDateString(),
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-07',
            'plan_code' => 'PREMIUM',
            'sum_insured' => 500000,
        ]);
        $res3->assertStatus(201);
        $this->assertEquals('850.50', $res3->json('data.premium'));

        // Case 4: BASIC (12), DOMESTIC (1.00), 100k (1.00), 75yo (2.00), 3 days
        // 12 * 3 * 2.00 * 1.00 * 1.00 = 72.00
        $res4 = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-CALC-4',
            'agent_id' => 'AGENT-CALC',
            'customer_name' => 'Elder Domestic',
            'date_of_birth' => Carbon::now()->subYears(75)->toDateString(),
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-03',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);
        $res4->assertStatus(201);
        $this->assertEquals('72.00', $res4->json('data.premium'));

        // Case 5: Exact specification example from Section 4
        // 1998-04-10 (28yo on 2026-11-10), PLUS, INTERNATIONAL, 300k, 5 days
        // Formula: 20 * 5 * 1.00 * 1.80 * 1.25 = 225.00
        $resSpec = $this->postJson('/api/policy-requests', [
            'client_request_id' => 'AGT-2026-0001',
            'agent_id' => 'AGENT-17',
            'customer_name' => 'Sample Customer',
            'date_of_birth' => '1998-04-10',
            'destination' => 'INTERNATIONAL',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-14',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ]);
        $resSpec->assertStatus(201);
        $this->assertEquals('225.00', $resSpec->json('data.premium'));
    }

    public function test_idempotent_duplicate_request_returns_existing(): void
    {
        $payload = [
            'client_request_id' => 'REQ-IDEMP-01',
            'agent_id' => 'AGENT-IDEMP',
            'customer_name' => 'Bob Marley',
            'date_of_birth' => '1980-02-20',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-10',
            'trip_end' => '2026-11-15',
            'plan_code' => 'PLUS',
            'sum_insured' => 300000,
        ];

        $first = $this->postJson('/api/policy-requests', $payload);
        $first->assertStatus(201);
        $firstId = $first->json('data.id');

        $second = $this->postJson('/api/policy-requests', $payload);
        $second->assertStatus(200)
            ->assertJson([
                'message' => 'Existing request returned',
                'data' => [
                    'id' => $firstId,
                    'client_request_id' => 'REQ-IDEMP-01',
                ],
            ]);

        $this->assertEquals(1, PolicyRequest::where('client_request_id', 'REQ-IDEMP-01')->count());
    }

    public function test_list_and_retrieve_policy_requests(): void
    {
        $this->postJson('/api/policy-requests', [
            'client_request_id' => 'REQ-LIST-1',
            'agent_id' => 'AGENT-L',
            'customer_name' => 'Person 1',
            'date_of_birth' => '1990-01-01',
            'destination' => 'DOMESTIC',
            'trip_start' => '2026-11-01',
            'trip_end' => '2026-11-05',
            'plan_code' => 'BASIC',
            'sum_insured' => 100000,
        ]);

        $created = PolicyRequest::first();

        // Test show
        $showResponse = $this->getJson('/api/policy-requests/' . $created->id);
        $showResponse->assertStatus(200)
            ->assertJson(['id' => $created->id]);

        // Test list
        $listResponse = $this->getJson('/api/policy-requests?status=VALIDATED');
        $listResponse->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'total']);
    }
}
