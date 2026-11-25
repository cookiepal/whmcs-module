<?php

declare(strict_types=1);

namespace CookiePal\Tests\Api;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\CookiePal\Hostname;
use WHMCS\Module\Server\CookiePal\ModuleException;

/** The service's Domain field, as the API keys it. */
final class HostnameTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function websites(): array
    {
        return [
            'bare' => ['example.com', 'example.com'],
            'upper case' => ['Example.COM', 'example.com'],
            'www' => ['www.example.com', 'example.com'],
            'protocol, www and path' => ['https://www.Example.com/shop?x=1#top', 'example.com'],
            'http with port' => ['http://example.com:8080/', 'example.com'],
            'userinfo' => ['https://user:secret@example.com/', 'example.com'],
            'subdomain kept' => ['shop.example.co.uk', 'shop.example.co.uk'],
            'www inside kept' => ['wwwexample.com', 'wwwexample.com'],
            'www as the name kept' => ['www.com', 'www.com'],
            'punycode kept' => ['xn--mnchen-3ya.de', 'xn--mnchen-3ya.de'],
            'spaces and trailing dot' => ['  example.com.  ', 'example.com'],
        ];
    }

    #[DataProvider('websites')]
    public function testNormalisesTheWebsiteDomain(string $raw, string $expected): void
    {
        $this->assertSame($expected, Hostname::website($raw));
    }

    #[RequiresPhpExtension('intl')]
    public function testAnInternationalDomainBecomesPunycode(): void
    {
        $this->assertSame('xn--mnchen-3ya.de', Hostname::website('MÜNCHEN.de'));
    }

    public function testAnEmptyDomainIsRefused(): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("Enter the customer's website domain on the service first.");

        Hostname::website('https://');
    }

    /** @return array<string, array{string}> */
    public static function notWebsites(): array
    {
        return [
            'a scheme without its colon' => ['http//example.com'],
            'a space' => ['exa mple.com'],
            'one label' => ['localhost'],
            'an IP address' => ['192.168.0.1'],
            'an underscore' => ['my_site.example.com'],
            'a label starting with a hyphen' => ['-example.com'],
        ];
    }

    #[DataProvider('notWebsites')]
    public function testSomethingThatIsNotAWebsiteDomainIsRefused(string $raw): void
    {
        $this->expectException(ModuleException::class);
        $this->expectExceptionMessage("The service's Domain field isn't a website domain such as example.com.");

        Hostname::website($raw);
    }
}
