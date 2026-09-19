<?php

declare(strict_types=1);

namespace Tests;

use EzPhp\Http\Request;
use EzPhp\Storage\SignedUrl;
use EzPhp\Storage\StorageException;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(SignedUrl::class)]
final class SignedUrlTest extends TestCase
{
    private const string SECRET = 'a-secret-of-sufficient-length';

    private int $now = 1_800_000_000;

    private function signer(string $secret = self::SECRET): SignedUrl
    {
        return new SignedUrl($secret, 'https://app.test/files/', fn (): int => $this->now);
    }

    /**
     * @return array{expires: string, signature: string}
     */
    private function parts(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $expires = $query['expires'] ?? '';
        $signature = $query['signature'] ?? '';

        return [
            'expires' => is_string($expires) ? $expires : '',
            'signature' => is_string($signature) ? $signature : '',
        ];
    }

    public function test_make_builds_a_url_under_the_base_with_expiry_and_signature(): void
    {
        $url = $this->signer()->make('reports/a b.pdf', 600);

        self::assertStringStartsWith('https://app.test/files/reports/a%20b.pdf?', $url);
        $parts = $this->parts($url);
        self::assertSame((string) ($this->now + 600), $parts['expires']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $parts['signature']);
    }

    public function test_a_fresh_link_verifies(): void
    {
        $signer = $this->signer();
        $parts = $this->parts($signer->make('reports/a.pdf', 600));

        self::assertTrue($signer->verify('reports/a.pdf', $parts['expires'], $parts['signature']));
        self::assertTrue($signer->verify('/reports/a.pdf', (int) $parts['expires'], $parts['signature']), 'a leading slash does not matter');
    }

    public function test_an_expired_link_is_rejected(): void
    {
        $signer = $this->signer();
        $parts = $this->parts($signer->make('a.pdf', 60));

        $this->now += 61;

        self::assertFalse($signer->verify('a.pdf', $parts['expires'], $parts['signature']));
    }

    public function test_a_link_is_valid_up_to_its_expiry_second(): void
    {
        $signer = $this->signer();
        $parts = $this->parts($signer->make('a.pdf', 60));

        $this->now += 60;

        self::assertTrue($signer->verify('a.pdf', $parts['expires'], $parts['signature']));
    }

    public function test_the_path_cannot_be_swapped(): void
    {
        $signer = $this->signer();
        $parts = $this->parts($signer->make('public/a.pdf', 600));

        self::assertFalse($signer->verify('private/secret.pdf', $parts['expires'], $parts['signature']));
    }

    public function test_the_expiry_cannot_be_extended(): void
    {
        $signer = $this->signer();
        $parts = $this->parts($signer->make('a.pdf', 60));

        self::assertFalse($signer->verify('a.pdf', (string) ((int) $parts['expires'] + 3600), $parts['signature']));
    }

    public function test_a_signature_from_another_secret_is_rejected(): void
    {
        $parts = $this->parts($this->signer('another-secret-that-is-long')->make('a.pdf', 600));

        self::assertFalse($this->signer()->verify('a.pdf', $parts['expires'], $parts['signature']));
    }

    /**
     * @return void
     */
    public function test_missing_or_malformed_parts_are_rejected(): void
    {
        $signer = $this->signer();
        $parts = $this->parts($signer->make('a.pdf', 600));

        self::assertFalse($signer->verify('a.pdf', null, $parts['signature']));
        self::assertFalse($signer->verify('a.pdf', $parts['expires'], null));
        self::assertFalse($signer->verify('a.pdf', $parts['expires'], ''));
        self::assertFalse($signer->verify('a.pdf', 'soon', $parts['signature']));
        self::assertFalse($signer->verify('a.pdf', $parts['expires'], 'not-a-signature'));
    }

    public function test_a_traversal_path_never_verifies(): void
    {
        $signer = $this->signer();

        self::assertFalse($signer->verify('../etc/passwd', (string) ($this->now + 60), str_repeat('a', 64)));
    }

    public function test_make_refuses_a_traversal_path(): void
    {
        $this->expectException(StorageException::class);

        $this->signer()->make('../etc/passwd', 60);
    }

    public function test_verify_request_reads_the_query_parameters(): void
    {
        $signer = $this->signer();
        $parts = $this->parts($signer->make('a.pdf', 600));

        $good = new Request(method: 'GET', uri: '/files/a.pdf', query: $parts);
        $bad = new Request(method: 'GET', uri: '/files/a.pdf', query: ['expires' => $parts['expires'], 'signature' => 'nope']);
        $none = new Request(method: 'GET', uri: '/files/a.pdf');

        self::assertTrue($signer->verifyRequest('a.pdf', $good));
        self::assertFalse($signer->verifyRequest('a.pdf', $bad));
        self::assertFalse($signer->verifyRequest('a.pdf', $none));
    }

    public function test_a_short_secret_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SignedUrl('short', 'https://app.test');
    }

    public function test_the_real_clock_is_used_by_default(): void
    {
        $signer = new SignedUrl(self::SECRET, 'https://app.test');
        $parts = $this->parts($signer->make('a.pdf', 60));

        self::assertGreaterThan(time(), (int) $parts['expires']);
        self::assertTrue($signer->verify('a.pdf', $parts['expires'], $parts['signature']));
    }
}
