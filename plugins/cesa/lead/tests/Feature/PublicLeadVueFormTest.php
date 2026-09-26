<?php

namespace Cesa\Lead\Tests\Feature;

use Cesa\Lead\Models\Lead;
use Cesa\Lead\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class PublicLeadVueFormTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_can_render_public_lead_form_page(): void
    {
        $response = $this->get('/lead');

        $response->assertOk()
            ->assertSee('pf-header-card', false)
            ->assertSee('wire:model', false)
            ->assertDontSee('window.__LEAD_CONFIG__', false);
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

    public function test_invalid_transaction_ranges_return_validation_errors_instead_of_server_errors(): void
    {
        $payload = Lead::factory()->raw(['phone' => '081234567890', 'phone_transaction_range' => 'Unknown range']);

        $this->postJson('/lead/api/submit', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone_transaction_range');

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_whatsapp_check_validates_phone_before_calling_the_gateway(): void
    {
        Http::fake();

        foreach (['123', ['081234567890']] as $phone) {
            $this->postJson('/lead/api/check-whatsapp', ['phone' => $phone])
                ->assertUnprocessable()
                ->assertJsonValidationErrors('phone');
        }

        Http::assertNothingSent();
    }

    public function test_submission_rejects_non_string_phone_without_a_server_error(): void
    {
        $payload = Lead::factory()->raw(['phone' => ['081234567890']]);

        $this->postJson('/lead/api/submit', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, Lead::query()->count());
    }

    public function test_disabled_whatsapp_validation_does_not_call_the_gateway(): void
    {
        Http::fake();

        $this->postJson('/lead/api/check-whatsapp', ['phone' => '081234567890'])
            ->assertUnprocessable()
            ->assertJson(['status' => 'failed']);

        Http::assertNothingSent();
    }

    public function test_public_submission_checks_whatsapp_registration_when_enabled(): void
    {
        config([
            'lead.whatsapp_validation.enabled'   => true,
            'lead.whatsapp_validation.endpoint'  => 'https://whatsapp.example.test',
            'lead.whatsapp_validation.token'     => 'test-token',
            'lead.whatsapp_validation.cache_ttl' => 0,
        ]);

        Cache::flush();
        Http::fake([
            'whatsapp.example.test/api/v1/number-checks' => Http::sequence()
                ->push(['data' => ['registered' => false]])
                ->push(['data' => ['registered' => true]]),
        ]);
        $payload = Lead::factory()->raw(['phone' => '081234567890']);

        $this->postJson('/lead/api/submit', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('phone');

        $this->assertSame(0, Lead::query()->count());

        $this->postJson('/lead/api/submit', $payload)
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame(1, Lead::query()->count());
    }

    public function test_public_api_requires_recaptcha_when_enabled(): void
    {
        config([
            'lead.security.recaptcha.enabled'    => true,
            'lead.security.recaptcha.site_key'   => 'test-site-key',
            'lead.security.recaptcha.secret_key' => 'test-secret-key',
        ]);

        Http::fake(['www.google.com/recaptcha/api/siteverify' => Http::response(['success' => false])]);
        $payload = Lead::factory()->raw(['phone' => '081234567890']);

        $this->postJson('/lead/api/submit', $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recaptcha_token');

        $this->postJson('/lead/api/submit', [...$payload, 'recaptcha_token' => 'invalid-token'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recaptcha_token');

        $this->assertSame(0, Lead::query()->count());
    }
}
