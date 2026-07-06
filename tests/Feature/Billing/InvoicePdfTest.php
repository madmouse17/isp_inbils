<?php

namespace Tests\Feature\Billing;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Billing\Models\Invoice;
use Modules\Billing\Support\Terbilang;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

class InvoicePdfTest extends TestCase
{
    use RefreshDatabase;
    use CreatesCompanyUser;

    public function test_terbilang(): void
    {
        $this->assertSame('tiga ratus tiga puluh tiga ribu rupiah', Terbilang::make(333000));
        $this->assertSame('satu juta lima ratus ribu rupiah', Terbilang::make(1500000));
        $this->assertSame('sebelas rupiah', Terbilang::make(11));
        $this->assertSame('nol rupiah', Terbilang::make(0));
    }

    public function test_pdf_route_returns_pdf(): void
    {
        $this->actingAs($this->createCompanyUser());
        $invoice = Invoice::factory()->create(['total' => 100000]);

        $response = $this->get(route('admin.invoices.pdf', $invoice));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
    }
}
