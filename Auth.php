<?php

namespace Omnistay\Amadeus;

use Omnistay\Exception\ProviderException;
use Omnistay\Http\JsonClient;

/**
 * Amadeus' access token: the client credentials grant
 * (POST /v1/security/oauth2/token, a form with grant_type, client_id and
 * client_secret), kept until a little before it expires (expires_in, about
 * thirty minutes), asked again after.
 */
final class Auth
{
    private ?string $token = null;
    private int $expires = 0;

    /** @var \Closure(): int */
    private readonly \Closure $clock;

    /** @param ?\Closure(): int $clock the time, in seconds */
    public function __construct(
        private readonly JsonClient $json,
        private readonly string $host,
        #[\SensitiveParameter] private readonly string $clientId,
        #[\SensitiveParameter] private readonly string $clientSecret,
        ?\Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** @return array<string, string> the header every call carries */
    public function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token()];
    }

    public function token(): string
    {
        $token = $this->token;
        if (null !== $token && ($this->clock)() < $this->expires) {
            return $token;
        }
        $data = $this->json->request('POST', $this->host.'/v1/security/oauth2/token', [
            'body' => ['grant_type' => 'client_credentials', 'client_id' => $this->clientId, 'client_secret' => $this->clientSecret],
        ], ['Content-Type' => 'application/x-www-form-urlencoded']);
        $token = $data?->string('access_token') ?? throw ProviderException::answered('Amadeus', 'POST /v1/security/oauth2/token', 200, 'No access token in the answer.');
        $this->token = $token;
        $this->expires = ($this->clock)() + max(60, ($data->int('expires_in') ?? 1799)) - 30;

        return $token;
    }
}
