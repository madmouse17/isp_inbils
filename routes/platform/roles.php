<?php

use App\Http\Controllers\Platform\CompanyRoleController;
use App\Http\Controllers\Platform\RoleTemplateController;
use Illuminate\Support\Facades\Route;

// Names carry the platform. prefix of the route shell (routes/platform.php).

Route::middleware(['auth:platform', 'platform.account', 'platform.2fa'])->group(function () {
    Route::get('role-templates', [RoleTemplateController::class, 'index'])->name('role-templates.index');
    Route::get('role-templates/create', [RoleTemplateController::class, 'create'])->name('role-templates.create');
    Route::get('role-templates/{template}/edit', [RoleTemplateController::class, 'edit'])->whereUlid('template')->name('role-templates.edit');

    // {company} is the tenant root. {role} stays a plain string: the company scope of the role model would hide it,
    // so CompanyRoleController looks it up inside the tenant's own companies (a foreign role answers 404).
    Route::prefix('companies/{company}')->whereUlid('company')->group(function () {
        Route::get('roles/create', [CompanyRoleController::class, 'create'])->name('companies.roles.create');
        Route::get('roles/{role}/edit', [CompanyRoleController::class, 'edit'])->whereUlid('role')->name('companies.roles.edit');
    });

    // Templates seed every new company and a repair rewrites a live company's roles: each write needs a fresh
    // platform password confirmation.
    Route::middleware('platform.password.confirm')->group(function () {
        Route::post('role-templates', [RoleTemplateController::class, 'store'])->name('role-templates.store');
        Route::put('role-templates/{template}', [RoleTemplateController::class, 'update'])->whereUlid('template')->name('role-templates.update');
        Route::delete('role-templates/{template}', [RoleTemplateController::class, 'destroy'])->whereUlid('template')->name('role-templates.destroy');

        Route::prefix('companies/{company}')->whereUlid('company')->group(function () {
            Route::post('roles', [CompanyRoleController::class, 'store'])->name('companies.roles.store');
            Route::put('roles/{role}', [CompanyRoleController::class, 'update'])->whereUlid('role')->name('companies.roles.update');
            Route::delete('roles/{role}', [CompanyRoleController::class, 'destroy'])->whereUlid('role')->name('companies.roles.destroy');
        });
    });
});
