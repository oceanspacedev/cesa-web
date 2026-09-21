<?php

namespace Cesa\IdCard\Support;

use Cesa\IdCard\Enums\BusinessEntity;
use Cesa\IdCard\Enums\Position;
use Cesa\IdCard\Models\IdCardRequest;
use Closure;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class IdCardRequestForm
{
    /**
     * @return array<Component>
     */
    public static function components(): array
    {
        return [
            TextInput::make('full_name')
                ->label(__('id-card::id-card.fields.full_name'))
                ->required()
                ->maxLength(255),
            Select::make('position')
                ->label(__('id-card::id-card.fields.position'))
                ->options(Position::class)
                ->required()
                ->enum(Position::class),
            Textarea::make('shipping_address')
                ->label(__('id-card::id-card.fields.shipping_address'))
                ->helperText(__('id-card::id-card.helpers.shipping_address'))
                ->required()
                ->rows(4)
                ->maxLength(2000)
                ->columnSpanFull(),
            Select::make('business_entity')
                ->label(__('id-card::id-card.fields.business_entity'))
                ->options(BusinessEntity::class)
                ->required()
                ->enum(BusinessEntity::class),
            TextInput::make('phone')
                ->label(__('id-card::id-card.fields.phone'))
                ->tel()
                ->required()
                ->maxLength(16)
                ->regex('/^(?:\+62|62|0)8[1-9][0-9]{7,10}$/')
                ->helperText(__('id-card::id-card.helpers.phone'))
                ->validationMessages([
                    'regex' => __('id-card::id-card.validation.phone'),
                ]),
            FileUpload::make('photo')
                ->label(__('id-card::id-card.fields.photo'))
                ->image()
                ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
                ->required()
                ->maxSize(5120)
                ->maxFiles(1)
                ->disk('local')
                ->directory('id-card/photos')
                ->visibility('private')
                ->fetchFileInformation(false)
                ->helperText(__('id-card::id-card.helpers.photo'))
                ->columnSpanFull()
                ->nestedRecursiveRules([
                    fn (?IdCardRequest $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($value instanceof TemporaryUploadedFile) {
                            return;
                        }

                        if (
                            ! $record?->exists
                            || ! is_string($value)
                            || $value !== $record->photo
                            || ! str_starts_with($value, 'id-card/photos/')
                            || str_contains($value, '..')
                        ) {
                            $fail(__('id-card::id-card.validation.photo'));
                        }
                    },
                ])
                ->getUploadedFileUsing(function (string $file, ?IdCardRequest $record): ?array {
                    if (
                        ! $record?->exists
                        || $file !== $record->photo
                        || ! str_starts_with($file, 'id-card/photos/')
                        || str_contains($file, '..')
                        || ! auth()->user()?->can('view', $record)
                    ) {
                        return null;
                    }

                    $disk = Storage::disk('local');

                    if (! $disk->exists($file)) {
                        return null;
                    }

                    return [
                        'name' => basename($file),
                        'size' => $disk->size($file),
                        'type' => $disk->mimeType($file),
                        'url'  => route('id-card.photos.show', ['idCardRequest' => $record]),
                    ];
                }),
        ];
    }
}
