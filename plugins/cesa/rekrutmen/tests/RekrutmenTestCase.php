<?php

namespace Cesa\Rekrutmen\Tests;

use Cesa\Rekrutmen\Enums\WhatsAppAccountStatus;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Cesa\Rekrutmen\RekrutmenServiceProvider;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;
use Tests\UsesSqliteInMemoryDatabase;
use Webkul\PluginManager\Package;

abstract class RekrutmenTestCase extends TestCase
{
    use RefreshDatabase;
    use UsesSqliteInMemoryDatabase;

    protected function setUp(): void
    {
        $this->useSqliteInMemoryDatabase();

        parent::setUp();
        $this->withoutVite();

        $this->artisan('migrate', [
            '--path'     => 'plugins/webkul/plugin-manager/database/migrations/2024_11_05_105102_create_plugins_table.php',
            '--realpath' => false,
        ]);

        DB::table('plugins')->updateOrInsert([
            'name' => 'rekrutmen',
        ], [
            'author'       => 'tests',
            'summary'      => 'tests',
            'description'  => 'tests',
            'is_active'    => true,
            'is_installed' => true,
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        Package::$plugins = [];

        $this->app->register(RekrutmenServiceProvider::class);

        require base_path('plugins/cesa/rekrutmen/routes/web.php');
        require base_path('plugins/cesa/rekrutmen/routes/api.php');

        $routes = app('router')->getRoutes();
        $routes->refreshNameLookups();
        $routes->refreshActionLookups();

        $this->artisan('migrate', [
            '--path'     => 'plugins/cesa/rekrutmen/database/migrations',
            '--realpath' => false,
        ]);

        if (Schema::hasTable('rekrutmen_job_applications') && ! Schema::hasColumn('rekrutmen_job_applications', 'ai_match_score')) {
            Schema::table('rekrutmen_job_applications', function (Blueprint $table) {
                $table->unsignedTinyInteger('ai_match_score')->nullable()->after('status');
                $table->string('ai_recommendation')->nullable()->after('ai_match_score');
                $table->text('ai_summary')->nullable()->after('ai_recommendation');
                $table->timestamp('ai_analyzed_at')->nullable()->after('ai_summary');
            });
        }
    }

    /**
     * @param  array<string, mixed>  $session
     */
    protected function fakeRekrutmenWhatsAppEngine(array $session = []): void
    {
        config([
            'rekrutmen.notifications.whatsapp.engine_url' => 'http://127.0.0.1:3318',
            'rekrutmen.notifications.whatsapp.auto_start' => false,
            'rekrutmen.notifications.whatsapp.enabled'    => true,
        ]);

        $sessionPayload = array_merge([
            'ok'           => true,
            'status'       => 'connected',
            'qr'           => null,
            'pairing_code' => null,
            'phone'        => '6281111111111',
            'error'        => null,
        ], $session);

        Http::fake(function (HttpRequest $request) use ($sessionPayload) {
            $url = $request->url();

            if (str_contains($url, '/send')) {
                return Http::response(['ok' => true, 'status' => 'sent'], 200);
            }

            if (str_contains($url, '/health')) {
                return Http::response(['ok' => true, 'sessions' => 1], 200);
            }

            return Http::response($sessionPayload, 200);
        });
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function makeConnectedWhatsAppAccount(array $attributes = []): WhatsAppAccount
    {
        return WhatsAppAccount::query()->create(array_merge([
            'name'         => 'HR WA',
            'phone_number' => '6281111111111',
            'is_default'   => true,
            'is_active'    => true,
            'status'       => WhatsAppAccountStatus::Connected,
        ], $attributes));
    }
}
