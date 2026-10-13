<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /** code => [name, sort_order]: the categories every company starts with. */
    private const DEFAULTS = [
        'RUMAHAN' => ['Rumahan', 1],
        'BISNIS' => ['Bisnis', 2],
        'KORPORAT' => ['Korporat', 3],
    ];

    /** Whole words in a package code or name; Korporat is checked before Bisnis, no match means Rumahan. */
    private const KEYWORDS = [
        'KORPORAT' => ['ENT', 'ENTERPRISE', 'DEDICATED', 'KORPORAT', 'CORPORATE'],
        'BISNIS' => ['BIZ', 'BUSINESS', 'BISNIS', 'SOHO', 'UMKM'],
    ];

    public function up(): void
    {
        Schema::create('service_package_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('company_id')->constrained()->cascadeOnUpdate()->restrictOnDelete();
            $table->string('code', 30);
            $table->string('name', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['company_id', 'code']);
            $table->index(['company_id', 'is_active', 'sort_order']);
        });

        foreach (DB::table('companies')->pluck('id') as $companyId) {
            foreach (self::DEFAULTS as $code => [$name, $sortOrder]) {
                DB::table('service_package_categories')->insert([
                    'company_id' => $companyId, 'code' => $code, 'name' => $name, 'sort_order' => $sortOrder,
                    'is_active' => true, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }

        Schema::table('service_packages', function (Blueprint $table) {
            $table->unsignedBigInteger('service_package_category_id')->nullable()->after('sla_tier_id');
        });

        $this->backfill();

        Schema::table('service_packages', function (Blueprint $table) {
            $table->unsignedBigInteger('service_package_category_id')->nullable(false)->change();
            $table->foreign('service_package_category_id')->references('id')->on('service_package_categories')->restrictOnDelete();
            $table->index(['company_id', 'service_package_category_id']);
        });
    }

    public function down(): void
    {
        Schema::table('service_packages', function (Blueprint $table) {
            $table->dropForeign(['service_package_category_id']);
            $table->dropIndex(['company_id', 'service_package_category_id']);
            $table->dropColumn('service_package_category_id');
        });

        Schema::dropIfExists('service_package_categories');
    }

    /** Soft-deleted packages included: they keep their category if they are ever restored. */
    private function backfill(): void
    {
        $categoryIds = [];
        foreach (DB::table('service_package_categories')->get(['id', 'company_id', 'code']) as $category) {
            $categoryIds[$category->company_id][$category->code] = $category->id;
        }

        DB::table('service_packages')->chunkById(500, function ($packages) use ($categoryIds): void {
            $groups = [];
            foreach ($packages as $package) {
                $code = $this->categoryCode($package->code, $package->name);
                $groups[$categoryIds[$package->company_id][$code]][] = $package->id;
            }

            foreach ($groups as $categoryId => $packageIds) {
                DB::table('service_packages')->whereIn('id', $packageIds)->update(['service_package_category_id' => $categoryId]);
            }
        });
    }

    private function categoryCode(string $code, string $name): string
    {
        $words = preg_split('/[^A-Z0-9]+/', strtoupper($code.' '.$name), -1, PREG_SPLIT_NO_EMPTY);

        foreach (self::KEYWORDS as $categoryCode => $keywords) {
            if (array_intersect($words, $keywords) !== []) {
                return $categoryCode;
            }
        }

        return 'RUMAHAN';
    }
};
