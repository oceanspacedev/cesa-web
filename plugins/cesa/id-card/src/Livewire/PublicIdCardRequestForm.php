<?php

namespace Cesa\IdCard\Livewire;

use Cesa\IdCard\Models\IdCardRequest;
use Cesa\IdCard\Support\IdCardRequestForm;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Pages\SimplePage;
use Filament\Schemas\Schema;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Webkul\PluginManager\Package;

class PublicIdCardRequestForm extends SimplePage
{
    use InteractsWithForms;

    protected static string $layout = 'id-card::layouts.form';

    protected string $view = 'id-card::livewire.public-id-card-request-form';

    public ?array $data = [];

    #[Locked]
    public bool $submitted = false;

    public function mount(): void
    {
        abort_unless(Package::isPluginInstalled('id-card'), 404);

        $this->form->fill();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components(IdCardRequestForm::components())
            ->model(IdCardRequest::class)
            ->columns(1)
            ->statePath('data');
    }

    public function submit(): void
    {
        abort_unless(Package::isPluginInstalled('id-card'), 404);

        if ($this->submitted) {
            return;
        }

        $rateLimitKey = 'id-card:submit:'.request()->ip();
        $maxAttempts = max(1, (int) config('id-card.submissions.max_attempts', 5));
        $decaySeconds = max(1, (int) config('id-card.submissions.decay_seconds', 60));

        if (RateLimiter::tooManyAttempts($rateLimitKey, $maxAttempts)) {
            throw ValidationException::withMessages([
                'data' => __('id-card::id-card.validation.rate_limited', [
                    'seconds' => RateLimiter::availableIn($rateLimitKey),
                ]),
            ]);
        }

        RateLimiter::hit($rateLimitKey, $decaySeconds);

        IdCardRequest::query()->create(Arr::only($this->form->getState(), [
            'full_name',
            'shipping_address',
            'business_entity',
            'position',
            'photo',
            'phone',
        ]));

        $this->submitted = true;
        $this->form->fill();
    }

    public function getTitle(): string
    {
        return __('id-card::id-card.title');
    }
}
