<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\ModuleLog;
use CookiePal\Tests\Support\ModuleTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use WHMCS\Module\Server\CookiePal\Module;
use WHMCS\Module\Server\CookiePal\Transport;

/** The module output on the client's product page, and its template rendered by Smarty. */
final class ClientAreaTest extends ModuleTestCase
{
    private const SNIPPET = "<!-- Start cookiepal banner -->\n"
        . '<script id="cookiepal" type="text/javascript" src="' . self::HANDLER . '"></script>' . "\n"
        . '<!-- End cookiepal banner -->';

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function viewFor(array $overrides): array
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody([
            $this->domainItem('example.com', $overrides),
        ]));

        return cookiepal_ClientArea($this->params())['templateVariables']['cookiepal'];
    }

    private static function compileDir(): string
    {
        return sys_get_temp_dir() . '/cookiepal-smarty-' . getmypid();
    }

    public static function tearDownAfterClass(): void
    {
        $dir = self::compileDir();
        if (is_dir($dir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($dir);
        }
        parent::tearDownAfterClass();
    }

    /** @param array{tabOverviewModuleOutputTemplate: string, templateVariables: array<string, mixed>} $output */
    private function render(array $output): string
    {
        $smarty = new \Smarty();
        $smarty->setTemplateDir(__DIR__ . '/../../modules/servers/cookiepal');
        $smarty->setCompileDir(self::compileDir());
        $smarty->assign('WEB_ROOT', 'https://billing.example.net');
        $smarty->assign($output['templateVariables']);

        return $smarty->fetch($output['tabOverviewModuleOutputTemplate']);
    }

    public function testTheClientAreaShowsTheSnippetPlanAndStatus(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody([
            $this->domainItem('example.com', ['verification' => 'verified']),
        ]));

        $result = cookiepal_ClientArea($this->params());

        $this->assertSame('templates/clientarea.tpl', $result['tabOverviewModuleOutputTemplate']);
        $vars = $result['templateVariables'];
        $this->assertSame(101, $vars['serviceid']);
        $this->assertSame([
            'state' => 'ready',
            'domain' => 'example.com',
            'snippet' => self::SNIPPET,
            'planLabel' => 'Growth',
            'installed' => true,
            'suspended' => false,
            'scheduledDeletion' => false,
        ], $vars['cookiepal']);
        $this->assertSame('Open CookiePal dashboard', $vars['cookiepalLang']['sso_button']);
        $this->assertSame([5], array_column($this->transport->requests, 'timeout'));
    }

    public function testTheClientAreaShowsASuspension(): void
    {
        $view = $this->viewFor(['suspended' => true, 'verification' => 'pending']);

        $this->assertTrue($view['suspended']);
        $this->assertFalse($view['installed']);
    }

    public function testTheClientAreaShowsAScheduledDeletion(): void
    {
        $this->assertTrue($this->viewFor(['scheduledDeletion' => '2026-11-27T10:00:00Z'])['scheduledDeletion']);
    }

    public function testAPlanOutsideTheFourIsCustom(): void
    {
        $this->assertSame('Custom', $this->viewFor(['plan' => 'other'])['planLabel']);
    }

    public function testAnUnexpectedHandlerGivesNoSnippet(): void
    {
        $this->assertSame('', $this->viewFor(['handler' => 'javascript:alert(1)'])['snippet']);
    }

    public function testTheClientAreaBeforeCreateIsPending(): void
    {
        $result = cookiepal_ClientArea($this->params());

        $this->assertSame('pending', $result['templateVariables']['cookiepal']['state']);
        $this->assertSame([], $this->transport->requests);
    }

    public function testTheClientAreaAfterTerminateSaysSo(): void
    {
        $this->seedService(terminatedAt: '2026-11-21 09:00:00');

        $this->assertSame(
            'terminated',
            cookiepal_ClientArea($this->params())['templateVariables']['cookiepal']['state']
        );
    }

    public function testAnApiErrorShowsTheErrorStateWithoutRetrying(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 503, ['message' => 'Service Unavailable']);

        $this->assertSame('error', cookiepal_ClientArea($this->params())['templateVariables']['cookiepal']['state']);
        $this->assertCount(1, $this->transport->requests);
    }

    public function testAnUnexpectedFailureShowsTheErrorStateAndIsLogged(): void
    {
        $this->seedService();
        Module::useTransport(new class implements Transport {
            public function send(string $method, string $url, array $headers, ?string $body, int $timeoutSeconds): array
            {
                throw new \RuntimeException('boom');
            }
        });

        $this->assertSame('error', cookiepal_ClientArea($this->params())['templateVariables']['cookiepal']['state']);
        $last = end(ModuleLog::$calls);
        $this->assertNotFalse($last);
        $this->assertSame(['ClientArea', 'RuntimeException: boom'], [$last['action'], $last['response']]);
    }

    public function testACustomerGoneFromCookiePalShowsTheMissingNotice(): void
    {
        $this->seedService();
        $this->transport->expect(
            'GET',
            $this->customerPath(),
            404,
            ['message' => 'customer not found', 'code' => 'CUSTOMER_NOT_FOUND']
        );

        $this->assertSame('missing', cookiepal_ClientArea($this->params())['templateVariables']['cookiepal']['state']);
        $this->assertCount(1, $this->transport->requests);
    }

    /** @return array<string, array{int, array<string, string>}> */
    public static function answersWaitingWontChange(): array
    {
        return [
            "another agency's customer" => [401, ['message' => 'Customer does not belong to this agency']],
            'a refused key' => [403, ['message' => 'Forbidden']],
        ];
    }

    /** @param array<string, string> $answer */
    #[DataProvider('answersWaitingWontChange')]
    public function testAnAnswerWaitingWontChangeTellsTheClientToContactTheHost(int $status, array $answer): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), $status, $answer);

        $html = $this->render(cookiepal_ClientArea($this->params()));

        $this->assertStringContainsString(
            'We can&#039;t load your CookiePal details because of a problem on our side. Contact us so we can fix it.',
            $html
        );
        $this->assertStringNotContainsString('dosinglesignon', $html);
    }

    public function testTheClientAreaForAWebsiteNoLongerThere(): void
    {
        $this->seedService();
        $this->transport->expect(
            'GET',
            $this->customerPath(),
            200,
            $this->customerBody([$this->domainItem('other.com')])
        );

        $this->assertSame('missing', cookiepal_ClientArea($this->params())['templateVariables']['cookiepal']['state']);
    }

    public function testTheTemplateRendersTheReadyState(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody([
            $this->domainItem('example.com', ['suspended' => true, 'scheduledDeletion' => '2026-11-27T10:00:00Z']),
        ]));

        $html = $this->render(cookiepal_ClientArea($this->params()));

        $this->assertStringContainsString(
            '>' . htmlspecialchars(self::SNIPPET, ENT_QUOTES) . '</textarea>',
            $html
        );
        $this->assertStringContainsString(
            'href="https://billing.example.net/clientarea.php?action=productdetails&amp;id=101&amp;dosinglesignon=1"',
            $html
        );
        $this->assertStringContainsString('This service is suspended, so the banner is switched off', $html);
        $this->assertStringContainsString('This website is scheduled for removal from CookiePal.', $html);
        $this->assertStringContainsString('<dd class="col-sm-8">Growth</dd>', $html);
        $this->assertStringContainsString('<span class="badge badge-secondary">No</span>', $html);
        $this->assertStringContainsString('data-failed="Select the code and copy it"', $html);
        $this->assertStringContainsString('&lt;head&gt;', $html);
        $this->assertStringNotContainsString('label-', $html);
    }

    public function testTheTemplateRendersEveryOtherState(): void
    {
        $this->seedService(terminatedAt: '2026-11-21 09:00:00');
        $terminated = $this->render(cookiepal_ClientArea($this->params()));
        $pending = $this->render(cookiepal_ClientArea($this->params(['serviceid' => 999])));

        $this->assertStringContainsString('This CookiePal service has ended.', $terminated);
        $this->assertStringContainsString('Your CookiePal banner is being set up.', $pending);
        $this->assertStringNotContainsString('dosinglesignon', $terminated . $pending);
    }

    public function testTheTemplateRendersAWebsiteNoLongerThere(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody([
            $this->domainItem('other.com'),
        ]));

        $html = $this->render(cookiepal_ClientArea($this->params()));

        $this->assertStringContainsString('This website is no longer on CookiePal.', $html);
        $this->assertStringNotContainsString('dosinglesignon', $html);
    }

    public function testTheTemplateRendersTheErrorState(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 503, ['message' => 'Service Unavailable']);

        $html = $this->render(cookiepal_ClientArea($this->params()));

        $this->assertStringContainsString("We couldn&#039;t load your CookiePal details right now.", $html);
        $this->assertStringNotContainsString('dosinglesignon', $html);
    }

    public function testTheTemplateShowsAnInstalledBanner(): void
    {
        $this->seedService();
        $this->transport->expect('GET', $this->customerPath(), 200, $this->customerBody([
            $this->domainItem('example.com', ['verification' => 'verified']),
        ]));

        $html = $this->render(cookiepal_ClientArea($this->params()));

        $this->assertStringContainsString('<span class="badge badge-success">Yes</span>', $html);
        $this->assertStringNotContainsString('badge-secondary', $html);
        $this->assertStringNotContainsString('This service is suspended', $html);
    }
}
