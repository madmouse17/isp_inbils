<?php

namespace App\Models\Core;

use App\Services\Core\IntegrationConfig;
use App\Traits\BelongsToCompany;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use IPLib\Address\AddressInterface;
use IPLib\Address\IPv6;
use IPLib\Factory;
use IPLib\ParseStringFlag;
use IPLib\Range\Type;

/**
 * A company's own account at one provider (Midtrans, WhatsApp, e-mail, RADIUS). The secrets sit encrypted in
 * `credentials` and are write-only: they never leave the server, a screen gets masked(). `settings` and `external_id`
 * hold what a screen may show. No activity log on the model, because an activity row would carry ciphertext and field
 * diffs: the controller, the job and the import command write explicit entries without values.
 *
 * @property int $id
 * @property int $company_id
 * @property string $provider
 * @property array<string, string|null>|null $credentials
 * @property array<string, mixed>|null $settings
 * @property string|null $external_id
 * @property bool $is_enabled
 * @property Carbon|null $verified_at
 * @property string|null $last_error
 * @property Carbon|null $last_checked_at
 */
final class CompanyIntegration extends Model
{
    use BelongsToCompany;

    public const PROVIDERS = ['midtrans', 'whatsapp', 'mail', 'radius'];

    public const STATES = ['empty', 'filled', 'verified', 'failed'];

    /** What a screen calls each provider; "Email" and "RADIUS" are kept terms. */
    public const LABELS = ['midtrans' => 'Midtrans', 'whatsapp' => 'WhatsApp', 'mail' => 'Email', 'radius' => 'RADIUS'];

    /** Write-only keys inside `credentials`. */
    public const SECRETS = [
        'midtrans' => ['server_key'],
        'whatsapp' => ['access_token', 'app_secret', 'webhook_verify_token'],
        'mail' => ['password'],
        'radius' => ['password'],
    ];

    /** The environments where a loopback host is a legitimate target (a local mail catcher, a RADIUS lab) and the platform's own stack is the laboratory. */
    private const LOOPBACK_ENVIRONMENTS = ['local', 'testing', 'e2e'];

    /** The libc spellings of an IPv4 address (2130706433, 0x7f.1, 127.1) reach the same hosts as the dotted quad. */
    private const ADDRESS_FORMS = ParseStringFlag::IPV4_MAYBE_NON_DECIMAL | ParseStringFlag::IPV4ADDRESS_MAYBE_NON_QUAD_DOTTED;

    protected $fillable = ['company_id', 'provider', 'credentials', 'settings', 'external_id', 'is_enabled'];

    protected $hidden = ['credentials'];

    protected $attributes = ['is_enabled' => false];

    protected static function booted(): void
    {
        $forget = static fn (self $row) => app(IntegrationConfig::class)->forget((int) $row->company_id, $row->provider);

        self::saved($forget);
        self::deleted($forget);
    }

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_enabled' => 'boolean',
            'verified_at' => 'datetime',
            'last_checked_at' => 'datetime',
        ];
    }

    /**
     * The `credentials` cast opens its ciphertext here. A ciphertext the application key no longer opens (the key changed
     * without APP_PREVIOUS_KEYS) reads as no secrets instead of throwing, so the screens, jobs and the shared WhatsApp
     * webhook that touch the row keep working: the admin enters the secrets again and that save replaces the old ciphertext.
     *
     * @return mixed the JSON the cast decodes, `[]` when the ciphertext does not open
     */
    public function fromEncryptedString($value)
    {
        try {
            return parent::fromEncryptedString($value);
        } catch (DecryptException) {
            return '[]';
        }
    }

    /** Does the loaded ciphertext fail to open? A row loaded without credentials (the platform pages select none) has nothing to fail. */
    private function credentialsUnreadable(): bool
    {
        $stored = $this->attributes['credentials'] ?? null;

        if (! is_string($stored) || $stored === '') {
            return false;
        }

        try {
            parent::fromEncryptedString($stored);
        } catch (DecryptException) {
            return true;
        }

        return false;
    }

    /** A stored secret, or null when none was ever set. */
    public function secret(string $key): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_string($value) && filled($value) ? $value : null;
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return ($this->settings ?? [])[$key] ?? $default;
    }

    /**
     * What a screen shows in place of each secret: the last four characters of a long one, nothing of a short one.
     *
     * @return array<string, ?string> every secret key of the provider
     */
    public function masked(): array
    {
        $masked = [];

        foreach (self::SECRETS[$this->provider] ?? [] as $key) {
            $secret = $this->secret($key);

            $masked[$key] = match (true) {
                $secret === null => null,
                mb_strlen($secret) >= 12 => 'tersimpan (••••'.mb_substr($secret, -4).')',
                default => 'tersimpan',
            };
        }

        return $masked;
    }

    /**
     * The fields of the settings screen, with the defaults an empty card starts from. Keys that only the import writes
     * (`allow_sandbox_in_production`, `base_url`, `transport`) are not among them.
     *
     * @return array<string, scalar|null>
     */
    public function publicSettings(): array
    {
        return match ($this->provider) {
            'midtrans' => ['is_production' => (bool) $this->setting('is_production', false)],
            'whatsapp' => ['phone_number_id' => $this->external_id],
            'mail' => [
                'host' => $this->setting('host'),
                'port' => $this->setting('port', 587),
                'scheme' => $this->setting('scheme', 'smtp'),
                'username' => $this->setting('username'),
                'from_address' => $this->setting('from_address'),
                'from_name' => $this->setting('from_name'),
            ],
            'radius' => [
                'host' => $this->setting('host'),
                'port' => $this->setting('port', 3306),
                'database' => $this->setting('database'),
                'username' => $this->setting('username'),
            ],
            default => [],
        };
    }

    /**
     * No row, or credentials that do not open, is empty; then a failed check is failed, a verified one is verified, anything
     * else is filled. A row loaded without its credentials is judged by its status columns alone, so no secret is read.
     */
    public static function stateOf(?self $row): string
    {
        return match (true) {
            $row === null, $row->credentialsUnreadable() => 'empty',
            $row->last_error !== null => 'failed',
            $row->verified_at !== null => 'verified',
            default => 'filled',
        };
    }

    public function touchVerified(): void
    {
        $this->forceFill(['verified_at' => now(), 'last_checked_at' => now(), 'last_error' => null])->save();
    }

    public function touchFailed(string $message): void
    {
        // 252 characters and the ellipsis fill the 255 of the column.
        $this->forceFill(['verified_at' => null, 'last_error' => Str::limit($message, 252), 'last_checked_at' => now()])->save();
    }

    /**
     * May a company point its mail or RADIUS server at $host? Anything that reaches this machine or the cloud
     * metadata service is refused: loopback (allowed in local, testing and e2e), the unspecified and "this network"
     * addresses, link-local (169.254.0.0/16 and fe80::/10) and multicast. The ISP's own LAN (RFC 1918) is allowed, it is
     * where these servers live. A name passes here and is checked again once it resolves (hostRefusal()).
     */
    public static function isAllowedHost(string $host): bool
    {
        $address = Factory::parseAddressString($host, self::ADDRESS_FORMS);

        return $address === null ? self::isAllowedName($host) : self::isAllowedAddress($address);
    }

    private static function isAllowedAddress(AddressInterface $address): bool
    {
        $blocked = [Type::T_UNSPECIFIED, Type::T_THISNETWORK, Type::T_LINKLOCAL, Type::T_LINKLOCAL_UNICAST, Type::T_MULTICAST, Type::T_LIMITEDBROADCAST];

        if (! app()->environment(self::LOOPBACK_ENVIRONMENTS)) {
            $blocked[] = Type::T_LOOPBACK;
        }

        return ! in_array($address->getRangeType(), $blocked, true);
    }

    private static function isAllowedName(string $host): bool
    {
        $label = '[A-Za-z0-9_]([A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?';

        // The last label is never all digits: a name like that is an address to inet_aton, and the tests above did not see it.
        // The D modifier keeps a trailing newline from passing as the end of the name.
        if (preg_match("/^(?=.{1,253}$){$label}(\\.{$label})*$/D", $host) !== 1 || ctype_digit(Str::afterLast($host, '.'))) {
            return false;
        }

        $name = strtolower($host);

        return app()->environment(self::LOOPBACK_ENVIRONMENTS) || ($name !== 'localhost' && ! str_ends_with($name, '.localhost'));
    }

    /**
     * The host policy at connect time. isAllowedHost() sees a name only: one that was fine when it was saved can resolve
     * into a refused range now, so the name is looked up and every address it gives is held to the same policy. Outside
     * the local environments (where a mail catcher or a RADIUS lab on the platform's own stack is a legitimate target)
     * the platform's own database, Redis and mail hosts are refused as well, by name and by address: the ISP LAN is
     * allowed, and these services sit on it.
     *
     * The refusal is one sentence for every cause, so that it does not tell a company which address is the platform's.
     *
     * shortcut: the connection resolves the name again, so a rebinding between this lookup and the connect is not caught
     * (pin the resolved address into the connection if that ever matters).
     *
     * @return string|null the Indonesian reason the host is refused; null when it may be used, and when the row names no
     *                     host (the provider's own check says what is missing)
     */
    public function hostRefusal(): ?string
    {
        $host = (string) $this->setting('host');

        if ($host === '') {
            return null;
        }

        $refusal = "Host {$host} tidak diizinkan (alamat lokal atau metadata).";

        if (! self::isAllowedHost($host)) {
            return $refusal;
        }

        $addresses = self::addressesOf($host);

        foreach ($addresses as $address) {
            if (! self::isAllowedHost($address)) {
                return $refusal;
            }
        }

        return self::reachesThePlatform($host, $addresses) ? $refusal : null;
    }

    /**
     * The gate of the runtime paths that dial a company's server (the mail transport, the RADIUS connection): a refused
     * host behaves as "not configured". The reason is written to the row, where the card shows it, and the log gets the
     * company and the provider only.
     */
    public function allowsConnection(): bool
    {
        $refusal = $this->hostRefusal();

        if ($refusal === null) {
            return true;
        }

        Log::warning('integration.host_refused', ['company_id' => (int) $this->company_id, 'provider' => $this->provider]);

        // The refusal comes back on every send and every job: a saved row is written once.
        if ($this->exists && $this->last_error !== $refusal) {
            $this->touchFailed($refusal);
        }

        return false;
    }

    /**
     * What a host stands for: the address itself, or every address its name resolves to.
     *
     * @return list<string>
     */
    private static function addressesOf(string $host): array
    {
        $literal = Factory::parseAddressString($host, self::ADDRESS_FORMS);

        // The AAAA lookup is silenced: a resolver with no answer warns instead of returning nothing.
        return $literal !== null
            ? [$literal->toString()]
            : [...(gethostbynamel($host) ?: []), ...array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6')];
    }

    /**
     * Does the host stand for one of the platform's own services (database, Redis, a mail server inside its network)?
     *
     * @param  list<string>  $addresses  what $host resolves to
     */
    private static function reachesThePlatform(string $host, array $addresses): bool
    {
        if (app()->environment(self::LOOPBACK_ENVIRONMENTS)) {
            return false;
        }

        $theirs = array_map(self::canonical(...), $addresses);
        $own = [config('database.connections.mysql.host'), config('database.redis.default.host')];
        $mail = config('mail.mailers.smtp.host');

        // A public provider (smtp.gmail.com, Mailgun) is shared by design: only a mail server inside the network is the platform's.
        if (is_string($mail) && $mail !== '' && ! self::isPublic(self::platformAddressesOf($mail))) {
            $own[] = $mail;
        }

        foreach (Arr::flatten($own) as $name) {
            if (! is_string($name) || $name === '') {
                continue;
            }

            if (strcasecmp($host, $name) === 0 || array_intersect($theirs, array_map(self::canonical(...), self::platformAddressesOf($name))) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The addresses of one of the platform's own hosts, kept a minute: every send would otherwise pay three extra
     * lookups, and a recreated container (new address) must not stay unrecognised for the life of a worker.
     *
     * @return list<string>
     */
    private static function platformAddressesOf(string $name): array
    {
        static $resolved = [];

        if (! isset($resolved[$name]) || time() - $resolved[$name][0] >= 60) {
            $resolved[$name] = [time(), self::addressesOf($name)];
        }

        return $resolved[$name][1];
    }

    /** @param  list<string>  $addresses  a name that resolves to nothing is not public */
    private static function isPublic(array $addresses): bool
    {
        foreach ($addresses as $address) {
            if (Factory::parseAddressString($address, self::ADDRESS_FORMS)?->getRangeType() !== Type::T_PUBLIC) {
                return false;
            }
        }

        return $addresses !== [];
    }

    /** One spelling per address: an IPv4-mapped IPv6 address (or a 6to4 one) is the IPv4 address it wraps. */
    private static function canonical(string $address): string
    {
        $parsed = Factory::parseAddressString($address, self::ADDRESS_FORMS);

        if ($parsed instanceof IPv6) {
            $parsed = $parsed->toIPv4() ?? $parsed;
        }

        return $parsed?->toString() ?? $address;
    }
}
