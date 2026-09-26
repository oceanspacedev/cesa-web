<?php

namespace Cesa\FormTransfer\Tests\Unit\Filament;

use Cesa\FormTransfer\Tests\FormTransferTestCase;

class PublicTransferPageLayoutTest extends FormTransferTestCase
{
    public function test_public_transfer_pages_match_the_waste_public_page_widths(): void
    {
        $indexView = file_get_contents(base_path('plugins/cesa/form-transfer/resources/views/livewire/public-transfer-request-index.blade.php'));
        $formView = file_get_contents(base_path('plugins/cesa/form-transfer/resources/views/livewire/public-transfer-request-form.blade.php'));

        $this->assertIsString($indexView);
        $this->assertIsString($formView);
        $this->assertStringContainsString('mx-auto max-w-4xl', $indexView);
        $this->assertStringContainsString('mx-auto max-w-xl', $formView);
        $this->assertStringContainsString('pf-page', $indexView);
        $this->assertStringContainsString('pf-page', $formView);
    }
}
