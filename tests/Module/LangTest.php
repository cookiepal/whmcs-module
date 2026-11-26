<?php

declare(strict_types=1);

namespace CookiePal\Tests\Module;

use CookiePal\Tests\Support\ModuleTestCase;
use WHMCS\Config\Setting;
use WHMCS\Module\Server\CookiePal\Lang;

/** Which language file the client's strings come from (fixtures in tests/fixtures/lang). */
final class LangTest extends ModuleTestCase
{
    private const FIXTURES = __DIR__ . '/../fixtures/lang';

    /** @param array<string, mixed> $client */
    private function copyLabel(array $client): string
    {
        return Lang::forParams(['clientsdetails' => $client], self::FIXTURES)['copy'];
    }

    public function testTheClientsLanguageOverridesEnglishKeyByKey(): void
    {
        $strings = Lang::forParams(['clientsdetails' => ['language' => 'French2']], self::FIXTURES);

        $this->assertSame('Copier', $strings['copy']);
        $this->assertSame('Copied', $strings['copied']);
    }

    public function testANameThatIsNotAFileNameIsEnglish(): void
    {
        $this->assertSame('Copy', $this->copyLabel(['language' => '../english']));
    }

    public function testALanguageWithoutAFileIsEnglish(): void
    {
        $this->assertSame('Copy', $this->copyLabel(['language' => 'klingon']));
    }

    public function testWithoutAClientLanguageTheSessionsIsUsed(): void
    {
        $_SESSION['Language'] = 'french2';

        $this->assertSame('Copier', $this->copyLabel(['language' => '']));
    }

    public function testWithoutAnyOtherTheSystemDefaultIsUsed(): void
    {
        Setting::$values['Language'] = 'french2';

        $this->assertSame('Copier', $this->copyLabel([]));
    }

    public function testATranslationThatDoesNotLoadIsEnglish(): void
    {
        $dir = sys_get_temp_dir() . '/cookiepal-lang-' . getmypid();
        @mkdir($dir);
        copy(self::FIXTURES . '/english.php', $dir . '/english.php');
        file_put_contents($dir . '/broken.php', "<?php\n\$_LANG['copy'] = 'Kopieren'\n\$_LANG['copied'] = ;\n");

        try {
            $strings = Lang::forParams(['clientsdetails' => ['language' => 'broken']], $dir);
        } finally {
            unlink($dir . '/broken.php');
            unlink($dir . '/english.php');
            rmdir($dir);
        }

        $this->assertSame(['copy' => 'Copy', 'copied' => 'Copied'], $strings);
    }

    public function testTheModulesEnglishHasEveryStringTheTemplateUses(): void
    {
        $template = (string) file_get_contents(__DIR__ . '/../../modules/servers/cookiepal/templates/clientarea.tpl');
        preg_match_all('/\{\$cookiepalLang\.([a-z_]+)/', $template, $used);
        $strings = Lang::forParams([]);

        foreach (array_merge($used[1], ['plan_other', 'sso_sign_in', 'sso_unavailable']) as $key) {
            $this->assertArrayHasKey($key, $strings);
        }
    }
}
