<?php

namespace Omnistay\Amadeus\Tests;

use Omnisense\Contracts\Policy\InvalidPolicyException;
use Omnisense\Contracts\Policy\Permission;
use Omnistay\Amadeus\AmadeusFactory;
use Omnistay\Amadeus\AmadeusProvider;
use Omnistay\Exception\OmnistayException;
use Omnistay\Exception\UsageLimitReachedException;
use Omnistay\Http\Data;
use Omnistay\Model\PropertyRef;
use Omnistay\Registry;
use Omnistay\Testing\RecordedHttpClient;
use PHPUnit\Framework\TestCase;

/** Amadeus' usage policy as declared (policy.json), and every call through the registry held to it. */
final class PolicyTest extends TestCase
{
    private const OPTIONS = ['client_id' => 'not-a-real-client-id', 'client_secret' => 'not-a-real-client-secret'];

    public function testThePolicyIsTheOneDeclared(): void
    {
        $registry = new Registry([new AmadeusFactory(self::http())], ['live' => ['factory' => 'amadeus', 'options' => ['environment' => 'production'] + self::OPTIONS], 'hotels' => ['factory' => 'amadeus', 'options' => self::OPTIONS]]);
        $production = $registry->policy('live');

        self::assertSame('amadeus', $production->provider);
        self::assertSame(['40/second/key/*'], array_map(static fn ($l) => $l->id(), $production->rateLimits ?? []));
        self::assertSame(Permission::Contract, $production->rights->commercial);
        self::assertSame(Permission::Unknown, $production->rights->caching->permission);
        self::assertFalse($production->rights->attribution->required);
        self::assertTrue($production->requirements->key);
        self::assertSame('2026-11-10', $production->provenance->verifiedAt);
        self::assertStringContainsString('Self-Service offer is gone', (string) $production->provenance->notes);
        self::assertSame(['10/second/key/*'], array_map(static fn ($l) => $l->id(), $registry->policy('hotels')->rateLimits ?? []), 'the test environment\'s own rate');
        self::assertSame($registry->policy('hotels')->toArray(), $registry->get('hotels')->capabilities()->policy()->toArray());
    }

    public function testACallOverTheSitesLimitIsNeverMade(): void
    {
        $http = self::http();
        $registry = new Registry([new AmadeusFactory($http)], ['hotels' => ['factory' => 'amadeus', 'options' => self::OPTIONS, 'policy' => ['rateLimits' => [['max' => 1, 'window' => 'minute', 'operations' => ['property']]]]]]);

        $registry->propertyOf(new PropertyRef('hotels', 'ARMADAIT'));
        try {
            $registry->propertyOf(new PropertyRef('hotels', 'ARMADAIT'));
            self::fail('The second description of the minute was asked.');
        } catch (UsageLimitReachedException $e) {
            self::assertInstanceOf(OmnistayException::class, $e, 'the family\'s catch holds it');
            self::assertSame('1/minute/application/property', $e->limit?->id());
        }
        self::assertCount(2, $http->requests, 'the token and one description: the refused call reached nothing');
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $http = self::http(['~/by-hotels\?~' => [429, '{"errors":[{"status":429,"code":38194,"title":"Too many requests"}]}', ['retry-after' => ['2']]]]);
        $registry = new Registry([new AmadeusFactory($http)], ['hotels' => ['factory' => 'amadeus', 'options' => self::OPTIONS]]);

        try {
            $registry->propertyOf(new PropertyRef('hotels', 'ARMADAIT'));
            self::fail('A 429 was read as a property.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(2, $e->retryAfter);
            self::assertSame('amadeus answered 429 Too Many Requests: try again in 2 s.', $e->getMessage());
        }
        try {
            $registry->propertyOf(new PropertyRef('hotels', 'ARMADAIT'));
            self::fail('A call went out while Amadeus asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(1, $e->retryAfter);
        }
        self::assertCount(2, $http->requests, 'the token and the refused call: the wait held the next request back');
    }

    public function testASiteCannotRaiseAmadeusRate(): void
    {
        $registry = new Registry([new AmadeusFactory(self::http())], ['hotels' => ['factory' => 'amadeus', 'options' => ['environment' => 'production'] + self::OPTIONS, 'policy' => ['rateLimits' => [['max' => 100, 'window' => 'second', 'scope' => 'key']]]]]);

        $this->expectException(InvalidPolicyException::class);
        $registry->search('hotels');
    }

    public function testThePolicyFileIsTheOneTheProviderNames(): void
    {
        self::assertFileExists(AmadeusProvider::POLICY);
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);
        self::assertSame('policy.json', Data::of($composer)->string('extra.omnisense.policy'));
    }

    /** @param array<string, array{0: int, 1: string, 2?: array<string, list<string>>}> $routes */
    private static function http(array $routes = []): RecordedHttpClient
    {
        $fixture = static fn (string $file): string => (string) file_get_contents(__DIR__.'/Fixtures/'.$file);

        return RecordedHttpClient::routes($routes + [
            '~/oauth2/token$~' => [200, $fixture('token.json')],
            '~/by-hotels\?~' => [200, $fixture('by-hotels.json')],
        ]);
    }
}
