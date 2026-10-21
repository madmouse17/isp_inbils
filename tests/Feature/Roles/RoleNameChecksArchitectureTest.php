<?php

namespace Tests\Feature\Roles;

use Illuminate\Support\Str;
use PhpToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Finder\Finder;
use Tests\TestCase;

/**
 * Roles are the company's to name, so no code decides by the name of a role: a business rule checks a permission, or a
 * marker permission such as `app.web`. The package's own `Role` model stays out of the code as well: it reads the roles of
 * every company, where `App\Models\Role` reads those of the company the code acts in.
 *
 * The scan covers the classes and the routes of the app and of every module; seeders, migrations and tests assign roles by
 * name on purpose and are outside it. A scan of this kind sees code, not intent, so the data providers below pin what it
 * catches and what it leaves alone.
 */
class RoleNameChecksArchitectureTest extends TestCase
{
    /** The only file that may name the package's role model: it extends it. */
    private const ROLE_MODEL = 'app/Models/Role.php';

    /**
     * The package's checks by role name and its `role()` and `withoutRole()` query scopes. A `role()` method of a class of
     * ours (`$this->role()`, `$request->role()`, `TenantRoles::role($company, $id)`) is not the scope: the scope takes the
     * names and is called on a query or on the user model.
     */
    private const ROLE_NAME_CALL = '/(?:->|::)\s*(?:hasRole|hasAnyRole|hasAllRoles|hasExactRoles|withoutRole)\s*\(|(?<!\$this)->\s*role\s*\(\s*[^\s)]|\bUser\s*::\s*role\s*\(/';

    /** A relation query on `roles`: the match is the call; its arguments must not filter on `name`. */
    private const ROLES_RELATION = '/(?:->|::)\s*(?:where|orWhere)(?:Has|DoesntHave|Relation)\s*\(\s*[\'"]roles[\'"]/';

    private const NAME_FILTER = '/[\'"](?:roles\.)?name[\'"]/';

    private const PACKAGE_ROLE_MODEL = '/Spatie\\\\Permission\\\\Models\\\\(?:Role\b|\{[^}]*\bRole\b)/';

    /** The package's middleware, and its string forms `role:admin` and `role_or_permission:admin|users.manage`. */
    private const ROLE_MIDDLEWARE = '/Spatie\\\\Permission\\\\Middleware\\\\Role(?:OrPermission)?Middleware|[\'"]role(?:_or_permission)?\s*:/';

    public function test_no_code_decides_by_the_name_of_a_role(): void
    {
        $found = [];

        foreach ($this->sourceFiles() as $file) {
            array_push($found, ...$this->violations($file->getContents(), Str::after($file->getRealPath(), base_path().'/')));
        }

        $this->assertSame([], $found, "Decide by permission, never by the name of a role:\n".implode("\n", $found));
    }

    /** @return array<string, array{string}> */
    public static function offences(): array
    {
        return [
            'hasRole' => ['<?php return $user->hasRole("admin");'],
            'hasAnyRole' => ['<?php return $user->hasAnyRole(["admin", "staff"]);'],
            'hasAllRoles' => ['<?php return $user->hasAllRoles(["admin", "staff"]);'],
            'hasExactRoles' => ['<?php return $user->hasExactRoles(["admin"]);'],
            'the role scope' => ["<?php return User::query()->role('admin')->get();"],
            'the role scope called statically' => ["<?php return User::role('admin')->get();"],
            'the withoutRole scope' => ["<?php return User::query()->withoutRole('admin')->get();"],
            'a roles relation filtered by name' => ["<?php User::query()->whereHas('roles', fn (\$roles) => \$roles->where('name', 'admin'));"],
            'a roles relation filtered by names' => ["<?php User::query()->orWhereHas(\"roles\", function (\$roles) {\n    \$roles->whereIn('name', ['admin']);\n});"],
            'a roles relation filtered by name in its relation form' => ["<?php User::query()->whereRelation('roles', 'name', 'admin');"],
            'the role scope on a property' => ["<?php return \$this->users->role('admin')->get();"],
            'no role by name' => ["<?php User::query()->whereDoesntHave('roles', fn (\$roles) => \$roles->where('roles.name', 'admin'));"],
            'the package role model' => ['<?php use Spatie\Permission\Models\Role;'],
            'the package role model in a group import' => ['<?php use Spatie\Permission\Models\{Permission, Role};'],
            'the package role middleware' => ['<?php use Spatie\Permission\Middleware\RoleMiddleware;'],
            'the role middleware string' => ["<?php Route::get('/x')->middleware('role:admin');"],
            'the role or permission middleware string' => ["<?php Route::get('/x')->middleware(['auth', 'role_or_permission:admin|users.manage']);"],
        ];
    }

    #[DataProvider('offences')]
    public function test_the_scan_catches_each_way_of_deciding_by_role_name(string $code): void
    {
        $this->assertNotSame([], $this->violations($code, 'sample.php'));
    }

    /** @return array<string, array{string}> */
    public static function permitted(): array
    {
        return [
            'a permission check' => ["<?php return \$user->can('users.manage');"],
            'holders found by permission' => ["<?php User::query()->whereHas('roles.permissions', fn (\$permissions) => \$permissions->where('name', 'users.manage'));"],
            'a roles relation filtered by key' => ["<?php User::query()->whereHas('roles', fn (\$roles) => \$roles->whereKey(5))->where('name', 'Ani');"],
            'a role name only shown' => ["<?php return \$user->roles->pluck('name')->implode(', ');"],
            'the company role model' => ['<?php use App\Models\Role;'],
            'the package permission model' => ['<?php use Spatie\Permission\Models\Permission;'],
            'a check in a comment' => ["<?php // \$user->hasRole('admin')\n/* \$user->hasAnyRole(['admin']) */\n/** use Spatie\\Permission\\Models\\Role; */"],
            'a route bound by the id of a role' => ["<?php Route::get('roles/{role:public_id}/edit', fn () => 1);"],
            'a column named role' => ["<?php return ['role' => \$contact->role, 'type' => 'splitter'];"],
            'a helper method named role' => ['<?php return $this->role();'],
            'a static helper named role' => ['<?php return TenantRoles::role($company, $id);'],
            'a role() helper without arguments' => ['<?php return $request->role();'],
        ];
    }

    #[DataProvider('permitted')]
    public function test_the_scan_leaves_permission_checks_alone(string $code): void
    {
        $this->assertSame([], $this->violations($code, 'sample.php'));
    }

    public function test_the_package_role_model_is_named_only_by_the_company_role_model(): void
    {
        $this->assertNotSame([], $this->violations('<?php use Spatie\Permission\Models\Role;', 'app/Services/Anything.php'));
        $this->assertSame([], $this->violations('<?php use Spatie\Permission\Models\Role as SpatieRole;', self::ROLE_MODEL));
    }

    /** @return Finder the PHP of the app and of every module: its classes and its routes */
    private function sourceFiles(): Finder
    {
        $roots = array_filter([
            app_path(),
            base_path('routes'),
            ...glob(base_path('Modules/*/app'), GLOB_ONLYDIR) ?: [],
            ...glob(base_path('Modules/*/routes'), GLOB_ONLYDIR) ?: [],
        ], 'is_dir');

        return Finder::create()->files()->in($roots)->name('*.php');
    }

    /**
     * "path:line: what" for every decision by role name in the code, comments left out.
     *
     * @return list<string>
     */
    private function violations(string $code, string $path): array
    {
        $code = $this->withoutComments($code);
        $found = [];
        $report = function (int $offset, string $what) use (&$found, $code, $path): void {
            $found[] = $path.':'.(substr_count($code, "\n", 0, $offset) + 1).': '.$what;
        };
        $patterns = [
            'a check by role name' => self::ROLE_NAME_CALL,
            'role middleware' => self::ROLE_MIDDLEWARE,
        ];

        if ($path !== self::ROLE_MODEL) {
            $patterns['the package role model'] = self::PACKAGE_ROLE_MODEL;
        }

        foreach ($patterns as $what => $pattern) {
            preg_match_all($pattern, $code, $matches, PREG_OFFSET_CAPTURE);

            foreach ($matches[0] as [, $offset]) {
                $report($offset, $what);
            }
        }

        preg_match_all(self::ROLES_RELATION, $code, $calls, PREG_OFFSET_CAPTURE);

        foreach ($calls[0] as [$call, $offset]) {
            if (preg_match(self::NAME_FILTER, $this->arguments($code, $offset + (int) strpos($call, '('))) === 1) {
                $report($offset, 'a roles relation filtered by name');
            }
        }

        return $found;
    }

    /** The code with every comment blanked and every line kept, so an offset still maps to its line. */
    private function withoutComments(string $code): string
    {
        return implode('', array_map(
            fn (PhpToken $token): string => $token->is([T_COMMENT, T_DOC_COMMENT]) ? str_repeat("\n", substr_count($token->text, "\n")) : $token->text,
            PhpToken::tokenize($code),
        ));
    }

    /** The text between the parenthesis that opens at $open and the one that closes it; quoted strings are skipped over. */
    private function arguments(string $code, int $open): string
    {
        $depth = 0;
        $quote = null;

        for ($i = $open, $length = strlen($code); $i < $length; $i++) {
            $char = $code[$i];

            if ($quote !== null) {
                if ($char === '\\') {
                    $i++;
                } elseif ($char === $quote) {
                    $quote = null;
                }

                continue;
            }

            if ($char === "'" || $char === '"') {
                $quote = $char;
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')' && --$depth === 0) {
                return substr($code, $open + 1, $i - $open - 1);
            }
        }

        return substr($code, $open + 1);
    }
}
