<?php

namespace Omnistay\Amadeus;

use Omnistay\Config;
use Omnistay\Exception\InvalidConfigException;
use Omnistay\Http\JsonClient;
use Omnistay\ProviderFactory;
use Omnistay\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * "amadeus": options client_id and client_secret (required: the API key
 * and secret of an Amadeus application, by reference - an environment
 * variable, a vault -, never in code), environment ("test", the default:
 * https://test.api.amadeus.com, where nothing booked is real; "production":
 * https://api.amadeus.com), host (another address, an enterprise one),
 * currency and language (the offers' when the query says none),
 * hotel_source (ALL, BEDBANK or DIRECTCHAIN), agency_email (the travel
 * agent's contact a booking carries; the guest's when none).
 */
final class AmadeusFactory extends ProviderFactory
{
    public const HOSTS = ['test' => 'https://test.api.amadeus.com', 'production' => 'https://api.amadeus.com'];

    /** @param ?\Closure(): int $clock the time, in seconds: when the token expires */
    public function __construct(private readonly ?HttpClientInterface $http = null, private readonly ?\Closure $clock = null)
    {
    }

    protected function populate(Config $c): void
    {
        $c->defaults([
            'omnistay.factory_name' => 'amadeus',
            'omnistay.factory_title' => 'Amadeus hotels',
            'omnistay.required_options' => ['client_id', 'client_secret'],
            'environment' => 'test',
            'host' => null,
            'currency' => null,
            'language' => null,
            'hotel_source' => 'ALL',
            'agency_email' => null,
        ]);
    }

    protected function build(Config $c): ProviderInterface
    {
        $environment = $c->string('environment') ?? 'test';
        $host = rtrim($c->string('host') ?? self::HOSTS[$environment] ?? throw new InvalidConfigException(\sprintf('Amadeus\' environment is "test" or "production", not "%s".', $environment)), '/');
        $json = new JsonClient($this->http ?? HttpClient::create(['timeout' => 30]), 'Amadeus', ['Accept' => 'application/vnd.amadeus+json, application/json']);

        return new AmadeusProvider(
            $json,
            new Auth($json, $host, (string) $c->string('client_id'), (string) $c->string('client_secret'), $this->clock),
            $host,
            self::name($c),
            'production' !== $environment,
            $c->string('currency'),
            $c->string('language'),
            $c->string('hotel_source') ?? 'ALL',
            $c->string('agency_email'),
        );
    }
}
