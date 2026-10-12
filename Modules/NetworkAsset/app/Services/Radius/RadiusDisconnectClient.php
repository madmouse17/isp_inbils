<?php

namespace Modules\NetworkAsset\Services\Radius;

/**
 * RFC 5176 Disconnect-Request sender. One attempt per call; the job owns retries.
 * Never logs packets, attributes or the secret; messages name only host:port.
 */
class RadiusDisconnectClient
{
    private const DISCONNECT_REQUEST = 40;

    private const DISCONNECT_ACK = 41;

    private const DISCONNECT_NAK = 42;

    private const ATTRIBUTE_TYPES = ['User-Name' => 1, 'Acct-Session-Id' => 44, 'NAS-IP-Address' => 4];

    private const ERROR_CAUSE = 101;

    /**
     * @param  array{'User-Name': string, 'Acct-Session-Id': string, 'NAS-IP-Address': string}  $attributes
     *
     * @throws RadiusException kind disabled while the switch is off
     */
    public function disconnect(string $host, int $port, string $secret, array $attributes): DisconnectResult
    {
        if (! config('services.radius.enabled')) {
            throw RadiusException::disabled();
        }

        $target = (str_contains($host, ':') ? "[{$host}]" : $host).":{$port}";
        $timeout = (float) config('services.radius.coa_timeout', 3);
        $identifier = random_int(0, 255);
        $request = $this->encode($identifier, $attributes, $secret);

        $socket = @stream_socket_client("udp://{$target}", $errno, $error, $timeout);
        if ($socket === false) {
            throw RadiusException::unavailable();
        }

        try {
            stream_set_timeout($socket, (int) $timeout, (int) (fmod($timeout, 1) * 1_000_000));
            if (@fwrite($socket, $request) !== strlen($request)) {
                throw RadiusException::unavailable();
            }
            $reply = fread($socket, 4096);
        } finally {
            fclose($socket);
        }

        if ($reply === false || $reply === '') {
            return new DisconnectResult('timeout');
        }

        return $this->decode($reply, substr($request, 4, 16), $secret, $identifier);
    }

    /** @param array<string, string> $attributes keys User-Name, Acct-Session-Id, NAS-IP-Address (IPv4) */
    public function encode(int $identifier, array $attributes, string $secret): string
    {
        $body = '';
        foreach (self::ATTRIBUTE_TYPES as $name => $type) {
            if (! isset($attributes[$name])) {
                continue;
            }
            $value = $name === 'NAS-IP-Address' ? $this->ipv4($attributes[$name]) : $attributes[$name];
            if (strlen($value) > 253) {
                throw new \InvalidArgumentException("Attribute {$name} is longer than 253 octets.");
            }
            $body .= pack('CC', $type, strlen($value) + 2).$value;
        }

        $head = pack('CCn', self::DISCONNECT_REQUEST, $identifier, 20 + strlen($body));

        return $head.md5($head.str_repeat("\0", 16).$body.$secret, true).$body;
    }

    public function decode(string $packet, string $requestAuthenticator, string $secret, int $identifier): DisconnectResult
    {
        $invalid = new DisconnectResult('invalid');
        if (strlen($packet) < 20) {
            return $invalid;
        }

        ['code' => $code, 'id' => $id, 'length' => $length] = unpack('Ccode/Cid/nlength', $packet);
        if ($id !== $identifier || ! in_array($code, [self::DISCONNECT_ACK, self::DISCONNECT_NAK], true)
            || $length < 20 || $length > strlen($packet)) {
            return $invalid;
        }

        $packet = substr($packet, 0, $length);
        $attributes = substr($packet, 20);
        $expected = md5(substr($packet, 0, 4).$requestAuthenticator.$attributes.$secret, true);
        if (! hash_equals($expected, substr($packet, 4, 16))) {
            return $invalid;
        }

        return $code === self::DISCONNECT_ACK
            ? new DisconnectResult('ack')
            : new DisconnectResult('nak', $this->errorCause($attributes));
    }

    private function errorCause(string $attributes): ?int
    {
        for ($offset = 0; $offset + 2 <= strlen($attributes);) {
            $type = ord($attributes[$offset]);
            $size = ord($attributes[$offset + 1]);
            if ($size < 2 || $offset + $size > strlen($attributes)) {
                return null;
            }
            if ($type === self::ERROR_CAUSE && $size === 6) {
                return unpack('N', substr($attributes, $offset + 2, 4))[1];
            }
            $offset += $size;
        }

        return null;
    }

    private function ipv4(string $address): string
    {
        $packed = filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? inet_pton($address) : false;

        return $packed === false ? throw new \InvalidArgumentException('NAS-IP-Address must be an IPv4 address.') : $packed;
    }
}
