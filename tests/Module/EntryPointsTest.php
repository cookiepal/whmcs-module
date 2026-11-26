<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\ModuleLog;
use CookiePal\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WHMCS\Module\Server\CookiePal\Module;
use WHMCS\Module\Server\CookiePal\Transport;

/** What WHMCS reads from the module folder and its cookiepal_<Function>s, and how calls are logged. */
final class EntryPointsTest extends ModuleTestCase
{
    private const MODULE_DIR = __DIR__ . '/../../modules/servers/cookiepal/';

    public function testMetaData(): void
    {
        $this->assertSame([
            'DisplayName' => 'CookiePal',
            'APIVersion' => '1.1',
            'RequiresServer' => true,
            'ServiceSingleSignOnLabel' => 'Open CookiePal dashboard',
        ], cookiepal_MetaData());
    }

    public function testCookiepalPhpRequiresEveryLibFile(): void
    {
        // WHMCS has no autoloader for the module; only the tests do, so a missing require_once would pass here.
        $entry = (string) file_get_contents(self::MODULE_DIR . 'cookiepal.php');
        $files = glob(self::MODULE_DIR . 'lib/*.php');
        $this->assertNotFalse($files);
        $this->assertNotSame([], $files);

        foreach ($files as $file) {
            $this->assertStringContainsString(
                "require_once __DIR__ . '/lib/" . basename($file) . "';",
                $entry,
                basename($file) . ' is not required by cookiepal.php'
            );
        }
    }

    public function testThePlanOptionIsADropdownOfTheFourPlans(): void
    {
        $options = cookiepal_ConfigOptions();

        $this->assertSame(['Plan'], array_keys($options));
        $this->assertSame('dropdown', $options['Plan']['Type']);
        $this->assertSame(
            ['free' => 'Free', 'starter' => 'Starter', 'growth' => 'Growth', 'business' => 'Business'],
            $options['Plan']['Options']
        );
        $this->assertArrayHasKey($options['Plan']['Default'], $options['Plan']['Options']);
    }

    /** @return list<array{string, string}> */
    public static function functionReturnTypes(): array
    {
        return [
            ['CreateAccount', 'string'],
            ['SuspendAccount', 'string'],
            ['UnsuspendAccount', 'string'],
            ['TerminateAccount', 'string'],
            ['ChangePackage', 'string'],
            ['TestConnection', 'array'],
            ['ServiceSingleSignOn', 'array'],
            ['ClientArea', 'array'],
            ['AdminServicesTabFields', 'array'],
            ['StartScan', 'string'],
            ['RefreshStatus', 'string'],
        ];
    }

    /** As in WHMCS's sample module: every function copes with empty params, and makes no call. */
    #[DataProvider('functionReturnTypes')]
    public function testEveryFunctionReturnsItsTypeForEmptyParams(string $function, string $type): void
    {
        $callable = 'cookiepal_' . $function;
        $this->assertTrue(is_callable($callable));

        $this->assertSame($type, gettype($callable([])));
        $this->assertSame([], $this->transport->requests);
    }

    public function testTheManifestNamesTheLogoAndTheLicenceShips(): void
    {
        $manifest = json_decode((string) file_get_contents(self::MODULE_DIR . 'whmcs.json'), true);

        $this->assertIsArray($manifest);
        $this->assertSame('whmcs-servers', $manifest['type']);
        $this->assertSame('cookiepal', $manifest['name']);
        $this->assertSame('MIT', $manifest['license']);
        $this->assertFileExists(self::MODULE_DIR . $manifest['logo']['filename']);
        $this->assertFileEquals(__DIR__ . '/../../LICENSE', self::MODULE_DIR . 'LICENSE');
    }

    public function testTheVersionIsInTheChangelog(): void
    {
        $this->assertStringContainsString(
            "\n## " . Module::VERSION . ' ',
            (string) file_get_contents(__DIR__ . '/../../CHANGELOG.md')
        );
    }

    public function testEveryCallOfAnActionIsLoggedUnderItsNameWithTheKeyMasked(): void
    {
        $this->seedService(100, 7, self::CUSTOMER, 'first.com');
        $this->transport
            ->expect('POST', '/external/domain', 409, ['message' => 'domain already exists', 'code' => 'DOMAIN_EXISTS'])
            ->expect('GET', $this->customerPath(), 200, $this->customerBody([$this->domainItem('example.com')]))
            ->expect('PUT', $this->domainPath('/plan'), 200, ['plan' => 'growth', 'changed' => false]);

        cookiepal_CreateAccount($this->params());

        $this->assertCount(3, ModuleLog::$calls);
        foreach (ModuleLog::$calls as $call) {
            $this->assertSame('CreateAccount', $call['action']);
            $this->assertSame([self::API_KEY], $call['replaceVars']);
        }
        $this->assertSame('CookiePal-WHMCS/' . Module::VERSION, $this->transport->requests[0]['headers']['User-Agent']);
    }

    public function testAnUnexpectedFailureIsLoggedAndReported(): void
    {
        Module::useTransport(new class implements Transport {
            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->assertSame('CookiePal module error: boom', cookiepal_CreateAccount($this->params()));
        $last = end(ModuleLog::$calls);
        $this->assertNotFalse($last);
        $this->assertSame('CreateAccount', $last['action']);
        $this->assertSame('RuntimeException: boom', $last['response']);
        $this->assertSame([self::API_KEY], $last['replaceVars']);
    }
}
