<?php

namespace Cesa\Lead\Tests\Feature;

use Cesa\Lead\Models\Lead;
use Cesa\Lead\Tests\TestCase;

class PublicLeadVueFormTest extends TestCase
{
    public function test_can_render_public_lead_vue_form_page(): void
    {
        $response = $this->get('/lead');

        $response->assertOk()
            ->assertSee('lead-public-form', false)
            ->assertSee('window.__LEAD_CONFIG__', false);
    }

    public function test_can_submit_public_lead_via_api_and_persist_lead(): void
    {
        $payload = [
            'name'                    => 'Budi Pratama',
            'phone'                   => '081234567890',
            'address'                 => 'Jl. Sudirman No. 45, Cirebon',
            'sales_person'            => 'Siti Rahma',
            'store_team_position'     => 'Promotor',
            'store_branch'            => 'Complete Selular Babakan',
            'phone_transaction_range' => 'Harga 2 - 3 juta',
        ];

        $response = $this->postJson('/lead/api/submit', $payload);

        $response->assertOk()
            ->assertJson([
                'success' => true,
            ]);

        $lead = Lead::query()->first();
        $this->assertNotNull($lead);
        $this->assertSame('BUDI PRATAMA', $lead->name);
        $this->assertSame('6281234567890', $lead->phone);
        $this->assertSame('Jl. Sudirman No. 45, Cirebon', $lead->address);
        $this->assertSame('Siti Rahma', $lead->sales_person);
        $this->assertSame('Promotor', $lead->store_team_position?->value ?? (string) $lead->store_team_position);
        $this->assertSame('Complete Selular Babakan', $lead->store_branch);
        $this->assertNull($lead->creator_id);

        $response->assertJson([
            'redirect_url' => $lead->getPublicProgressUrl(),
        ]);
    }

    public function test_public_lead_submission_validates_required_fields(): void
    {
        $response = $this->postJson('/lead/api/submit', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors([
                'name',
                'phone',
                'address',
                'sales_person',
                'store_team_position',
                'store_branch',
            ]);
    }

    public function test_public_lead_submission_validates_duplicate_phone(): void
    {
        Lead::query()->create([
            'name'                    => 'EXISTING LEAD',
            'phone'                   => '6281234567890',
            'address'                 => 'Jl. Mawar',
            'sales_person'            => 'Sales A',
            'store_team_position'     => 'Kasir',
            'store_branch'            => 'Complete Selular Babakan',
            'phone_transaction_range' => 'Harga di bawah 2 juta',
        ]);

        $response = $this->postJson('/lead/api/submit', [
            'name'                => 'Another Lead',
            'phone'               => '081234567890',
            'address'             => 'Jl. Melati',
            'sales_person'        => 'Sales B',
            'store_team_position' => 'Frontliner',
            'store_branch'        => 'Complete Selular Babakan',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['phone']);
    }
}
