<?php

namespace Tests\Feature\Lifecycle;

use App\Http\Controllers\Admin\LifecycleController;
use App\Models\Core\Company;
use App\Models\Core\Location;
use App\Models\User;
use App\Services\Core\LifecycleService;
use App\Support\PublicId;
use App\Traits\HasLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use LogicException;
use Modules\Service\Models\Announcement;
use Modules\Service\Models\BandwidthProfile;
use Modules\Service\Models\ServicePackage;
use PDOException;
use Tests\TestCase;
use Tests\Traits\CreatesCompanyUser;

/** A flat master that packages point at; the probes borrow real tables, so no master has to adopt the trait first. */
class LifecycleProbe extends BandwidthProfile
{
    use HasLifecycle;

    protected $table = 'bandwidth_profiles';

    public static function lifecycleNoun(): string
    {
        return 'Profil uji';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    public static function lifecycleReferences(): ?array
    {
        return ['paket' => ['service_packages.bandwidth_profile_id']];
    }
}

/** A tree whose filters come from a column, so it has no active flag. */
class LifecycleTreeProbe extends Location
{
    use HasLifecycle;

    protected $table = 'locations';

    public static function lifecycleNoun(): string
    {
        return 'Lokasi uji';
    }

    public static function lifecycleFilters(): array
    {
        return self::statusFilters('type', ['region', 'pop']);
    }

    public static function lifecycleReferences(): ?array
    {
        return ['lokasi turunan' => ['locations.parent_id']];
    }
}

/** Two labels, one of them over two JSON paths of the same table. */
class LifecycleMixedProbe extends BandwidthProfile
{
    use HasLifecycle;

    protected $table = 'bandwidth_profiles';

    public static function lifecycleNoun(): string
    {
        return 'Profil campur';
    }

    public static function lifecycleFilters(): array
    {
        return self::ACTIVE_FILTERS;
    }

    public static function lifecycleReferences(): ?array
    {
        return [
            'paket' => ['service_packages.bandwidth_profile_id'],
            'catatan' => ['activity_log.properties->probe_id', 'activity_log.properties->other_id'],
        ];
    }
}

class LifecycleProbePolicy
{
    public function update(User $user, Model $model): bool
    {
        return $user->can('service.update');
    }

    public function restore(User $user, Model $model): bool
    {
        return $user->can('service.delete');
    }
}

class LifecycleMechanismTest extends TestCase
{
    use CreatesCompanyUser;
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createCompanyUser();

        Gate::policy(LifecycleProbe::class, LifecycleProbePolicy::class);
        Gate::policy(LifecycleTreeProbe::class, LifecycleProbePolicy::class);

        Route::middleware(['web', 'auth'])->prefix('admin')->name('admin.')->group(function (): void {
            LifecycleController::routes('lifecycle-probes', LifecycleProbe::class);
            LifecycleController::routes('lifecycle-tree-probes', LifecycleTreeProbe::class, ['restore'], 'trees');
            LifecycleController::routes('plain-models', Announcement::class, ['restore']);
        });
        Route::getRoutes()->refreshNameLookups();
    }

    public function test_status_scope_maps_default_aktif_nonaktif_dihapus_semua_and_ignores_unknown(): void
    {
        $this->probe('Aktif');
        $this->probe('Nonaktif', ['is_active' => false]);
        $this->probe('Dihapus')->delete();

        $names = fn (mixed $status): array => LifecycleProbe::query()->lifecycleStatus($status)->orderBy('name')->pluck('name')->all();

        foreach ([null, '', 'bogus', ['aktif'], 5] as $default) {
            $this->assertSame(['Aktif', 'Nonaktif'], $names($default));
        }
        $this->assertSame(['Aktif'], $names('aktif'));
        $this->assertSame(['Nonaktif'], $names('nonaktif'));
        $this->assertSame(['Dihapus'], $names('dihapus'));
        $this->assertSame(['Aktif', 'Dihapus', 'Nonaktif'], $names('semua'));
        $this->assertTrue(LifecycleProbe::isDeactivatable());
    }

    public function test_status_scope_uses_the_filters_an_entity_declares(): void
    {
        $root = $this->location('Root', ['type' => 'region']);
        $this->location('Pop satu', ['type' => 'pop', 'parent_id' => $root->id]);
        $this->location('Pop dua', ['type' => 'pop', 'parent_id' => $root->id])->delete();
        $this->location('Situs', ['type' => 'site', 'parent_id' => $root->id]);

        $names = fn (mixed $status): array => LifecycleTreeProbe::query()->lifecycleStatus($status)->orderBy('name')->pluck('name')->all();

        $this->assertSame(['Pop satu'], $names('pop'));
        $this->assertSame(['Root'], $names('region'));
        $this->assertSame(['Pop satu', 'Root', 'Situs'], $names('aktif'), 'a value this entity does not declare is the default view');
        $this->assertSame(['Pop dua'], $names('dihapus'));
        $this->assertFalse(LifecycleTreeProbe::isDeactivatable());
    }

    public function test_reference_counts_include_trashed_children(): void
    {
        $probe = $this->probe('Dipakai');
        $this->package($probe);
        $this->package($probe)->delete();
        $this->probe('Lain');

        $counted = LifecycleProbe::query()->withReferenceCounts()->findOrFail($probe->id);
        $expected = [['label' => 'paket', 'count' => 2]];

        $this->assertSame($expected, $counted->referenceCounts());
        $this->assertSame($expected, $probe->countReferences());
        $this->assertSame(['deleted_at' => null, 'references' => $expected], $counted->lifecycleAttributes());
        $this->assertNull($probe->referenceCounts(), 'a query that did not run the sub-selects has no counts');
        $this->assertSame([['label' => 'paket', 'count' => 0]], LifecycleProbe::query()->withReferenceCounts()->where('name', 'Lain')->firstOrFail()->referenceCounts());
    }

    public function test_deleted_at_is_an_iso_string_in_the_resource_fields(): void
    {
        $probe = $this->probe('Dihapus');
        $probe->delete();

        $fresh = $probe->fresh();
        $fields = $fresh->lifecycleAttributes();

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}\.\d+Z$/', $fields['deleted_at']);
        $this->assertTrue(Carbon::parse($fields['deleted_at'])->equalTo($fresh->deleted_at));
    }

    public function test_self_reference_counts_use_an_aliased_inner_table(): void
    {
        $root = $this->location('Root');
        $this->location('Anak satu', ['parent_id' => $root->id]);
        $this->location('Anak dua', ['parent_id' => $root->id])->delete();

        $query = LifecycleTreeProbe::query()->withReferenceCounts()->orderBy('id');
        $counts = $query->get()->mapWithKeys(fn (LifecycleTreeProbe $location): array => [$location->name => $location->referenceCounts()[0]['count']])->all();

        $this->assertStringContainsString('as `lifecycle_ref`', $query->toSql());
        $this->assertSame(['Root' => 2, 'Anak satu' => 0], $counts, 'the trashed child counts; the leaf does not count itself');
    }

    public function test_reference_columns_may_address_a_json_path_and_sum_across_columns_and_labels(): void
    {
        $probe = LifecycleMixedProbe::query()->findOrFail($this->probe('Satu')->id);
        $other = LifecycleMixedProbe::query()->findOrFail($this->probe('Dua')->id);
        $this->package($probe);
        $this->package($probe);
        foreach ([['probe_id' => $probe->id], ['other_id' => $probe->id], ['probe_id' => $other->id], ['unrelated' => $probe->id]] as $properties) {
            DB::table('activity_log')->insert(['description' => 'probe', 'properties' => json_encode($properties), 'public_id' => PublicId::new()]);
        }
        DB::table('activity_log')->insert(['description' => 'probe', 'properties' => null, 'public_id' => PublicId::new()]);

        $counted = LifecycleMixedProbe::query()->withReferenceCounts()->orderBy('name')->get();

        $this->assertSame([['label' => 'paket', 'count' => 0], ['label' => 'catatan', 'count' => 1]], $counted->firstWhere('id', $other->id)->referenceCounts());
        $this->assertSame([['label' => 'paket', 'count' => 2], ['label' => 'catatan', 'count' => 2]], $counted->firstWhere('id', $probe->id)->referenceCounts());
        $this->assertSame($counted->firstWhere('id', $probe->id)->referenceCounts(), $probe->countReferences());
        $this->assertSame($counted->firstWhere('id', $other->id)->referenceCounts(), $other->countReferences());
    }

    public function test_reference_count_sub_selects_read_a_foreign_key_index(): void
    {
        foreach ([LifecycleProbe::class, LifecycleTreeProbe::class] as $class) {
            $subSelects = $class::query()->withReferenceCounts()->orderBy('name')->limit(25)->explain()->where('select_type', 'DEPENDENT SUBQUERY');

            $this->assertCount(1, $subSelects);
            $this->assertSame('ref', $subSelects->first()->type);
            $this->assertNotNull($subSelects->first()->key);
        }
    }

    public function test_a_record_that_keeps_its_own_delete_rules_has_no_counts_and_cannot_be_force_deleted(): void
    {
        $record = new class() extends Location
        {
            use HasLifecycle;

            protected $table = 'locations';

            public static function lifecycleNoun(): string
            {
                return 'Tiket uji';
            }

            public static function lifecycleFilters(): array
            {
                return [];
            }

            public static function lifecycleReferences(): ?array
            {
                return null;
            }
        };

        $this->assertNull($record::query()->withReferenceCounts()->getQuery()->columns);
        $this->assertNull($record->referenceCounts());
        $this->assertSame([], $record->countReferences());

        $this->expectException(LogicException::class);
        LifecycleService::destroyIfUnreferenced($record);
    }

    public function test_describe_skips_zero_counts(): void
    {
        $this->assertSame('3 paket, 12 tiket', LifecycleService::describe([
            ['label' => 'paket', 'count' => 3],
            ['label' => 'langganan', 'count' => 0],
            ['label' => 'tiket', 'count' => 12],
        ]));
        $this->assertSame('', LifecycleService::describe([['label' => 'paket', 'count' => 0]]));
    }

    public function test_destroy_hard_deletes_an_unreferenced_row(): void
    {
        $probe = $this->probe('Bebas');
        $seen = [];

        LifecycleService::destroyIfUnreferenced($probe, function (Model $locked) use (&$seen): void {
            $seen[] = [$locked::class, $locked->name];
        });

        $this->assertDatabaseMissing('bandwidth_profiles', ['id' => $probe->id]);
        $this->assertSame([[LifecycleProbe::class, 'Bebas']], $seen);
    }

    public function test_destroy_locks_the_row_before_it_counts(): void
    {
        $probe = $this->probe('Terkunci');
        DB::flushQueryLog();
        DB::enableQueryLog();

        LifecycleService::destroyIfUnreferenced($probe);

        $statements = array_column(DB::getQueryLog(), 'query');
        $this->assertStringEndsWith('for update', $statements[0]);
        $this->assertStringContainsString('count(*)', $statements[1]);
    }

    public function test_destroy_refuses_a_referenced_row_and_names_the_counts(): void
    {
        $probe = $this->probe('Dipakai');
        $this->package($probe);
        $ran = false;
        $destroy = function () use ($probe, &$ran): void {
            LifecycleService::destroyIfUnreferenced($probe, function () use (&$ran): void {
                $ran = true;
            });
        };

        $this->assertSame('Profil uji masih dipakai 1 paket. Nonaktifkan saja.', $this->refusal($destroy));

        $this->package($probe)->delete();
        $this->assertSame('Profil uji masih dipakai 2 paket. Nonaktifkan saja.', $this->refusal($destroy), 'a trashed child still points at the row');
        $this->assertDatabaseHas('bandwidth_profiles', ['id' => $probe->id]);
        $this->assertFalse($ran, 'the before-delete hook runs only after the zero-reference check');
    }

    public function test_destroy_of_a_record_that_cannot_be_deactivated_does_not_suggest_it(): void
    {
        $root = $this->location('Induk');
        $this->location('Anak', ['parent_id' => $root->id]);

        $message = $this->refusal(fn () => LifecycleService::destroyIfUnreferenced($root));

        $this->assertSame('Lokasi uji masih dipakai 1 lokasi turunan, jadi tidak bisa dihapus.', $message);
    }

    public function test_destroy_maps_a_child_inserted_after_the_count_to_the_same_refusal(): void
    {
        $probe = $this->probe('Berebut');

        $message = $this->refusal(fn () => LifecycleService::destroyIfUnreferenced($probe, function (Model $locked): void {
            $this->package($locked);
        }));

        $this->assertSame('Profil uji baru saja dipakai data lain. Nonaktifkan saja.', $message);
        $this->assertDatabaseHas('bandwidth_profiles', ['id' => $probe->id]);
        $this->assertSame(0, ServicePackage::query()->withTrashed()->where('bandwidth_profile_id', $probe->id)->count(), 'the child is rolled back with the delete');
    }

    public function test_deactivate_and_activate_flip_the_flag_and_ignore_a_repeat(): void
    {
        $probe = $this->probe('Saklar');

        LifecycleService::deactivate($probe);
        LifecycleService::deactivate($probe);
        $this->assertFalse($probe->fresh()->is_active);

        LifecycleService::activate($probe);
        LifecycleService::activate($probe);
        $this->assertTrue($probe->fresh()->is_active);
    }

    public function test_a_record_without_an_active_flag_cannot_be_deactivated_or_activated(): void
    {
        $location = $this->location('Tetap');

        foreach ([LifecycleService::deactivate(...), LifecycleService::activate(...)] as $action) {
            try {
                $action($location);
                $this->fail('Expected a LogicException.');
            } catch (LogicException $e) {
                $this->assertStringContainsString('no active flag', $e->getMessage());
            }
        }
    }

    public function test_restore_refuses_while_the_parent_row_is_trashed(): void
    {
        $parent = $this->location('Induk');
        $child = $this->location('Anak', ['parent_id' => $parent->id]);
        $child->delete();
        $parent->delete();
        $restored = [];
        $hook = function (Model $locked) use (&$restored): void {
            $restored[] = $locked->name;
        };

        $message = $this->refusal(fn () => LifecycleService::restore($child, $hook));

        $this->assertSame('Lokasi uji tidak bisa dipulihkan karena induknya, Induk, masih dihapus. Pulihkan induknya dulu.', $message);
        $this->assertTrue($child->fresh()->trashed());

        LifecycleService::restore($parent, $hook);
        LifecycleService::restore($child, $hook);

        $this->assertFalse($parent->fresh()->trashed());
        $this->assertFalse($child->fresh()->trashed());
        $this->assertSame(['Induk', 'Anak'], $restored, 'the after-restore hook runs once per restored row, never for a refusal');
    }

    public function test_restore_maps_a_unique_violation_to_a_validation_error(): void
    {
        $probe = $this->probe('Kembar');
        $probe->delete();
        LifecycleProbe::restoring(function (): void {
            throw new UniqueConstraintViolationException('mysql', 'update `bandwidth_profiles` set `deleted_at` = ? where `id` = ?', [], new PDOException('Duplicate entry'));
        });

        $message = $this->refusal(fn () => LifecycleService::restore($probe));

        $this->assertSame('Profil uji tidak bisa dipulihkan karena kode atau namanya sudah dipakai data lain. Ubah data itu dulu.', $message);
        $this->assertTrue($probe->fresh()->trashed());
    }

    public function test_restore_of_a_live_row_is_a_no_op(): void
    {
        $probe = $this->probe('Hidup');
        $ran = false;

        LifecycleService::restore($probe, function () use (&$ran): void {
            $ran = true;
        });

        $this->assertFalse($ran);
        $this->assertCount(0, $this->auditEntries('restored', $probe));
    }

    public function test_actions_write_audit_entries(): void
    {
        $toggled = $this->probe('Saklar');
        $removed = $this->probe('Hilang');
        $returned = $this->probe('Kembali');
        $returned->delete();

        LifecycleService::deactivate($toggled);
        LifecycleService::deactivate($toggled);
        LifecycleService::activate($toggled);
        LifecycleService::restore($returned);
        LifecycleService::destroyIfUnreferenced($removed);

        $this->assertCount(1, $this->auditEntries('deactivated', $toggled), 'a repeat is not logged twice');
        $this->assertCount(1, $this->auditEntries('activated', $toggled));
        $this->assertCount(1, $this->auditEntries('restored', $returned));

        $deleted = $this->auditEntries('force_deleted', $removed);
        $this->assertCount(1, $deleted);
        $this->assertNull($deleted->first()->subject_id, 'the row is gone, so it is not the subject');
        $this->assertSame($this->admin->company_id, $deleted->first()->company_id);
        $this->assertSame('Hilang', json_decode($deleted->first()->properties, true)['name']);
        $this->assertSame($toggled->id, $this->auditEntries('deactivated', $toggled)->first()->subject_id);
    }

    public function test_routes_register_names_authorize_and_404_for_other_company(): void
    {
        $this->assertTrue(Route::has('admin.lifecycle-probes.deactivate'));
        $this->assertTrue(Route::has('admin.lifecycle-probes.activate'));
        $this->assertTrue(Route::has('admin.lifecycle-probes.restore'));
        $this->assertTrue(Route::has('admin.trees.restore'), 'the name argument replaces the uri');
        $this->assertFalse(Route::has('admin.trees.deactivate'), 'only the listed actions are registered');
        $this->assertFalse(Route::has('admin.lifecycle-tree-probes.restore'));

        $probe = $this->probe('Lewat route');
        $trashed = $this->probe('Terhapus');
        $trashed->delete();
        $foreign = BandwidthProfile::factory()->create(['company_id' => Company::factory()->create()->id, 'name' => 'Asing']);
        $viewer = User::factory()->create(['company_id' => $this->admin->company_id, 'email_verified_at' => now(), 'is_active' => true]);
        $url = fn (string $action, Model $model): string => route('admin.lifecycle-probes.'.$action, $model->public_id);

        $this->actingAs($viewer)->postJson($url('deactivate', $probe))->assertForbidden();
        $this->actingAs($viewer)->postJson($url('restore', $trashed))->assertForbidden();
        $this->assertTrue($probe->fresh()->is_active);
        $this->assertTrue($trashed->fresh()->trashed());

        $this->actingAs($this->admin);
        $this->postJson($url('deactivate', $foreign))->assertNotFound();
        $this->postJson($url('restore', $foreign))->assertNotFound();
        $this->postJson('/admin/lifecycle-probes/'.$probe->id.'/deactivate')->assertNotFound();
        $this->postJson($url('deactivate', $trashed))->assertNotFound();
        $this->assertTrue(BandwidthProfile::withoutCompany()->findOrFail($foreign->id)->is_active);

        $this->from('/admin/back')->post($url('deactivate', $probe))
            ->assertRedirect('/admin/back')->assertSessionHas('success', 'Profil uji dinonaktifkan.');
        $this->assertFalse($probe->fresh()->is_active);

        $override = '?lifecycleModel='.urlencode(Announcement::class).'&record='.PublicId::new();
        $this->from('/admin/back')->post($url('activate', $probe).$override)->assertSessionHas('success', 'Profil uji diaktifkan.');
        $this->assertTrue($probe->fresh()->is_active, 'the model and the record come from the route, never from the request');

        $this->from('/admin/back')->post($url('restore', $trashed))->assertSessionHas('success', 'Profil uji dipulihkan.');
        $this->assertFalse($trashed->fresh()->trashed());
    }

    public function test_a_refusal_reaches_the_client_on_the_lifecycle_key(): void
    {
        $parent = $this->location('Induk');
        $child = $this->location('Anak', ['parent_id' => $parent->id]);
        $child->delete();
        $parent->delete();

        $this->actingAs($this->admin)
            ->postJson(route('admin.trees.restore', $child->public_id))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['lifecycle' => 'Lokasi uji tidak bisa dipulihkan karena induknya, Induk, masih dihapus. Pulihkan induknya dulu.']);
    }

    public function test_a_model_without_the_trait_is_refused(): void
    {
        $this->withoutExceptionHandling();
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage(Announcement::class.' must use HasLifecycle.');

        $this->actingAs($this->admin)->postJson(route('admin.plain-models.restore', PublicId::new()));
    }

    private function probe(string $name, array $attributes = []): LifecycleProbe
    {
        $profile = BandwidthProfile::factory()->create($attributes + ['name' => $name, 'company_id' => $this->admin->company_id]);

        return LifecycleProbe::query()->withTrashed()->findOrFail($profile->id);
    }

    private function location(string $name, array $attributes = []): LifecycleTreeProbe
    {
        $code = Str::slug($name);
        $location = Location::factory()->create($attributes + ['name' => $name, 'code' => $code, 'path' => $code, 'company_id' => $this->admin->company_id]);

        return LifecycleTreeProbe::query()->withTrashed()->findOrFail($location->id);
    }

    private function package(Model $profile): ServicePackage
    {
        return ServicePackage::factory()->create(['company_id' => $this->admin->company_id, 'bandwidth_profile_id' => $profile->id]);
    }

    private function refusal(callable $action): string
    {
        try {
            $action();
        } catch (ValidationException $e) {
            return $e->errors()['lifecycle'][0];
        }

        $this->fail('Expected a ValidationException on the lifecycle key.');
    }

    /** @return Collection<int, object> */
    private function auditEntries(string $action, Model $model)
    {
        return DB::table('activity_log')->where('description', $action)->where('properties->id', $model->public_id)->get();
    }
}
