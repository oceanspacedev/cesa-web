<?php

namespace Cesa\Rekrutmen\Http\Controllers;

use App\Http\Controllers\Controller;
use Cesa\Rekrutmen\Http\Requests\ConnectWhatsAppAccountRequest;
use Cesa\Rekrutmen\Http\Requests\SaveMailSettingsRequest;
use Cesa\Rekrutmen\Http\Requests\SaveWhatsAppSettingsRequest;
use Cesa\Rekrutmen\Http\Requests\TestMailSettingsRequest;
use Cesa\Rekrutmen\Http\Requests\TestWhatsAppAccountRequest;
use Cesa\Rekrutmen\Http\Requests\UpsertWhatsAppAccountRequest;
use Cesa\Rekrutmen\Models\JobApplication;
use Cesa\Rekrutmen\Models\MailSetting;
use Cesa\Rekrutmen\Models\WhatsAppAccount;
use Cesa\Rekrutmen\Models\WhatsAppSetting;
use Cesa\Rekrutmen\Services\RekrutmenMailer;
use Cesa\Rekrutmen\Services\WhatsAppGateway;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

class RekrutmenCommunicationSettingsController extends Controller
{
    public function __construct(
        protected RekrutmenMailer $mailer,
        protected WhatsAppGateway $whatsAppGateway,
    ) {}

    public function getMailSettings(): JsonResponse
    {
        return response()->json(MailSetting::current()->toApiArray());
    }

    public function saveMailSettings(SaveMailSettingsRequest $request): JsonResponse
    {
        $setting = $this->mailer->save($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Pengaturan email rekrutmen berhasil disimpan. Perubahan langsung dipakai tanpa mengubah SMTP aplikasi lain.',
            'data'    => $setting->toApiArray(),
        ]);
    }

    public function testMailSettings(TestMailSettingsRequest $request): JsonResponse
    {
        $payload = $request->validated();
        $recipient = $payload['recipient'];
        unset($payload['recipient']);

        $settings = $this->transientMailSetting($payload);

        try {
            $this->mailer->sendTest($recipient, $settings);
        } catch (Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengirim email tes: '.$e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Email tes berhasil dikirim ke '.$recipient.'.',
        ]);
    }

    public function getWhatsAppSettings(): JsonResponse
    {
        $ready = $this->whatsAppGateway->engineReady();
        $accounts = WhatsAppAccount::query()->orderByDesc('is_default')->orderBy('name')->get()
            ->map(fn (WhatsAppAccount $account): array => $this->whatsAppGateway->session($account, $ready))->values();
        $gateway = WhatsAppSetting::current()->toApiArray();
        $gateway['engine_ready'] = $ready;

        return response()->json([
            'gateway'  => $gateway,
            'accounts' => $accounts,
        ]);
    }

    public function whatsappSenders(): JsonResponse
    {
        if (! Gate::allows('manage_rekrutmen_whatsapp')) {
            Gate::authorize('viewAny', JobApplication::class);
        }

        $ready = $this->whatsAppGateway->engineReady();
        $accounts = WhatsAppAccount::query()->active()->orderByDesc('is_default')->orderBy('name')->get()
            ->map(function (WhatsAppAccount $account) use ($ready): array {
                $session = $this->whatsAppGateway->session($account, $ready);

                return array_intersect_key($session, array_flip([
                    'id', 'name', 'phone_number', 'is_active', 'is_default', 'status', 'delivery_ready',
                ]));
            });

        return response()->json(['accounts' => $accounts->values(), 'engine_ready' => $ready]);
    }

    public function saveWhatsAppSettings(SaveWhatsAppSettingsRequest $request): JsonResponse
    {
        $setting = $this->whatsAppGateway->saveSettings($request->validated());
        $data = $setting->toApiArray();
        $data['engine_ready'] = $this->whatsAppGateway->engineReady();

        return response()->json([
            'success' => true,
            'message' => 'Pengaturan WhatsApp rekrutmen berhasil disimpan.',
            'data'    => $data,
        ]);
    }

    public function connectWhatsAppAccount(ConnectWhatsAppAccountRequest $request): JsonResponse
    {
        $phone = $this->whatsAppGateway->formatPhone($request->validated('phone_number'));
        $name = trim((string) $request->validated('name', ''));
        $mode = (string) $request->validated('mode', 'qr');

        if ($name === '') {
            $name = $phone ? 'WhatsApp '.$phone : 'WhatsApp Rekrutmen';
        }

        $account = WhatsAppAccount::withTrashed()->firstOrCreate([
            'connection_request_key' => hash('sha256', $request->user()->id.':'.($request->validated('request_key') ?? Str::uuid())),
        ], [
            'name'         => $name,
            'phone_number' => $phone,
            'is_active'    => true,
            'is_default'   => ! WhatsAppAccount::query()->exists(),
        ]);

        abort_if($account->trashed(), 409, 'Permintaan ini sudah digunakan untuk akun yang dihapus. Buat koneksi baru.');

        $result = $this->whatsAppGateway->connect($account, $mode, $mode === 'pairing' ? $phone : null);

        return response()->json([
            'success' => $result['success'],
            'message' => $result['message'],
            'data'    => $result['data'] ?? $account->fresh()?->toApiArray(),
        ], $result['success'] ? 201 : 422);
    }

    public function reconnectWhatsAppAccount(ConnectWhatsAppAccountRequest $request, WhatsAppAccount $account): JsonResponse
    {
        $mode = (string) $request->validated('mode', 'qr');
        $phone = $this->whatsAppGateway->formatPhone($request->validated('phone_number')) ?: $account->phone_number;
        $result = $this->whatsAppGateway->connect($account, $mode, $mode === 'pairing' ? $phone : null);

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    public function sessionWhatsAppAccount(WhatsAppAccount $account): JsonResponse
    {
        return response()->json($this->whatsAppGateway->session($account));
    }

    public function disconnectWhatsAppAccount(WhatsAppAccount $account): JsonResponse
    {
        $result = $this->whatsAppGateway->disconnect($account);

        return response()->json(array_merge($result, [
            'data' => $account->fresh()?->toApiArray() ?? $account->toApiArray(),
        ]), $result['success'] ? 200 : 503);
    }

    public function updateWhatsAppAccount(UpsertWhatsAppAccountRequest $request, WhatsAppAccount $account): JsonResponse
    {
        $data = $this->normalizeAccountPayload($request->validated(), $account);
        $account->fill($data);
        $account->save();

        return response()->json([
            'success' => true,
            'message' => 'Nomor WhatsApp pengirim berhasil diperbarui.',
            'data'    => $account->fresh()?->toApiArray() ?? $account->toApiArray(),
        ]);
    }

    public function destroyWhatsAppAccount(WhatsAppAccount $account): JsonResponse
    {
        $result = $this->whatsAppGateway->disconnect($account);
        if (! $result['success']) {
            return response()->json($result, 503);
        }

        $account->delete();

        return response()->json([
            'success' => true,
            'message' => 'Nomor WhatsApp pengirim berhasil dihapus.',
        ]);
    }

    public function makeDefaultWhatsAppAccount(WhatsAppAccount $account): JsonResponse
    {
        $account->is_default = true;
        $account->is_active = true;
        $account->save();

        return response()->json([
            'success' => true,
            'message' => 'Nomor '.$account->name.' dijadikan pengirim default.',
            'data'    => $account->fresh()?->toApiArray() ?? $account->toApiArray(),
        ]);
    }

    public function testWhatsAppAccount(TestWhatsAppAccountRequest $request, WhatsAppAccount $account): JsonResponse
    {
        $result = $this->whatsAppGateway->testAccount(
            $account,
            $request->validated('recipient')
        );

        return response()->json($result, $result['success'] ? 200 : 422);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function transientMailSetting(array $payload): MailSetting
    {
        $stored = MailSetting::query()->first();
        $settings = $stored ? $stored->replicate() : MailSetting::fromEnv();
        $settings->exists = false;

        if (! array_key_exists('password', $payload) || ! filled($payload['password'])) {
            unset($payload['password']);
            if ($stored) {
                $settings->password = $stored->password;
            }
        }

        $payload['enabled'] = true;
        $settings->fill($payload);

        return $settings;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    protected function normalizeAccountPayload(array $payload, ?WhatsAppAccount $account = null): array
    {
        unset($payload['api_key'], $payload['endpoint'], $payload['route_key'], $payload['phone_number']);

        $payload['is_default'] = (bool) ($payload['is_default'] ?? $account?->is_default ?? false);
        $payload['is_active'] = (bool) ($payload['is_active'] ?? $account?->is_active ?? true);

        return $payload;
    }
}
