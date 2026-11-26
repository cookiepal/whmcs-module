<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use WHMCS\Module\Server\CookiePal\Snippet;

final class SnippetTest extends TestCase
{
    public function testTheInstallCodeMatchesTheDashboards(): void
    {
        $this->assertSame(
            "<!-- Start cookiepal banner -->\n"
            . '<script id="cookiepal" type="text/javascript" '
            . 'src="https://cdn.cookiepal.io/client_data/abc/script.js"></script>'
            . "\n<!-- End cookiepal banner -->",
            Snippet::forHandler('https://cdn.cookiepal.io/client_data/abc/script.js')
        );
    }

    /** @return array<string, array{string}> */
    public static function unsafeHandlers(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'plain http' => ['http://cdn.cookiepal.io/a/script.js'],
            'a quote' => ['https://cdn.cookiepal.io/a"onload="x.js'],
            'a trailing newline' => ["https://cdn.cookiepal.io/a/script.js\n"],
            'no path' => ['https://cdn.cookiepal.io'],
        ];
    }

    #[DataProvider('unsafeHandlers')]
    public function testAnUnsafeHandlerGivesNoInstallCode(string $handler): void
    {
        $this->assertFalse(Snippet::isSafeHandler($handler));
        $this->assertSame('', Snippet::forHandler($handler));
    }
}
