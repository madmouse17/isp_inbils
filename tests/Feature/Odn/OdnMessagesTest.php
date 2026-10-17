<?php

namespace Tests\Feature\Odn;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\NetworkAsset\Models\OdnPort;
use Modules\NetworkAsset\Models\OdnSplitter;
use Tests\TestCase;
use Tests\Traits\BuildsIpamOdnFixtures;
use Tests\Traits\BuildsOdnTopology;

/** What the ODN pages answer a person: the flash after a change and the reason when a change is refused, in Indonesian. */
class OdnMessagesTest extends TestCase
{
    use BuildsIpamOdnFixtures;
    use BuildsOdnTopology;
    use RefreshDatabase;

    /** @var array<string, mixed> */
    private array $net;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->bootIpamOdnWorld();
        $this->net = $this->topology($this->company);
    }

    public function test_the_ports_and_splitters_flash_in_indonesian(): void
    {
        $this->post(route('admin.odn.pon-ports.store', $this->net['olt']), ['count' => 2])
            ->assertSessionHas('success', '2 port PON dibuat di OLT-A1.');
        $this->post(route('admin.odn.splitters.store', $this->net['odp']), ['outputs' => 8])
            ->assertSessionHas('success', 'Splitter 1:8 ditambahkan ke ODP-A11.');
        $odnSplitter = OdnSplitter::query()->where('network_asset_id', $this->net['odp']->id)->firstOrFail();

        $this->put(route('admin.odn.splitters.update', $odnSplitter), ['label' => 'Tray 1'])->assertSessionHas('success', 'Splitter diperbarui.');
        $this->delete(route('admin.odn.splitters.destroy', $odnSplitter))->assertSessionHas('success', 'Splitter dihapus.');
        $pon = OdnPort::query()->where('kind', OdnPort::KIND_PON)->orderBy('number')->firstOrFail();
        $this->delete(route('admin.odn.ports.destroy', $pon))->assertSessionHas('success', 'Port PON dihapus.');
    }

    public function test_the_drop_flashes_and_its_refusals_are_indonesian(): void
    {
        [, $outputs] = $this->splitter($this->net['odp'], 4);
        $subscription = $this->makeSubscription($this->company, 'active', $this->net['pop']);
        $other = $this->makeSubscription($this->company, 'active', $this->net['pop']);

        $this->post(route('admin.odn.drops.store', $outputs[0]), ['subscription_id' => $subscription->public_id])->assertSessionHas('success', 'Port drop dicatat.');
        $this->postJson(route('admin.odn.drops.store', $outputs[0]), ['subscription_id' => $other->public_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['port' => 'Port ini sudah dipakai.']);
        $this->postJson(route('admin.odn.drops.store', $outputs[1]), [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subscription_id' => 'langganan wajib diisi.']);
        $this->delete(route('admin.odn.drops.destroy', $outputs[0]))->assertSessionHas('success', 'Port drop dilepas.');
        $this->postJson(route('admin.odn.drops.destroy', $outputs[0]), ['_method' => 'DELETE'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['port' => 'Port ini tidak punya port drop untuk dilepas.']);
    }

    public function test_the_refusals_of_ports_and_splitters_are_indonesian(): void
    {
        $this->postJson(route('admin.odn.pon-ports.store', $this->net['odp']), ['count' => 1])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset' => 'Port PON hanya dapat dibuat pada OLT.']);
        $this->postJson(route('admin.odn.pon-ports.store', $this->net['olt']), ['count' => 0])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['count' => 'jumlah port harus bernilai antara 1 dan 64.']);
        $this->postJson(route('admin.odn.splitters.store', $this->net['olt']), ['outputs' => 8])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['asset' => 'Splitter dipasang di ODC atau ODP.']);
        $this->postJson(route('admin.odn.splitters.store', $this->net['odp']), ['outputs' => 3])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outputs' => 'rasio yang dipilih tidak valid.']);

        $pon = $this->ponPorts($this->net['olt'], 1);
        [$splitter] = $this->splitter($this->net['odc'], 4, $pon[0]);
        $this->deleteJson(route('admin.odn.ports.destroy', $pon[0]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['port' => 'Sebuah splitter terhubung ke port PON ini. Putuskan lebih dulu.']);
        $this->putJson(route('admin.odn.splitters.update', $splitter), ['input_port_id' => $splitter->ports()->firstOrFail()->public_id])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['input_port_id' => 'Splitter tidak dapat dihubungkan ke keluarannya sendiri atau ke apa pun di hilirnya.']);
    }
}
