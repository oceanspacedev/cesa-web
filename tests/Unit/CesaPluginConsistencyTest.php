<?php

use PHPUnit\Framework\Assert;

test('cesa plugin package identities stay aligned', function (): void {
    foreach (cesaPluginPaths() as $pluginName => $pluginPath) {
        $studlyName = cesaStudlyPluginName($pluginName);
        $composer = cesaComposerJson($pluginPath);
        $providerClass = "Cesa\\{$studlyName}\\{$studlyName}ServiceProvider";
        $providerPath = "{$pluginPath}/src/{$studlyName}ServiceProvider.php";
        $pluginClassPath = "{$pluginPath}/src/{$studlyName}Plugin.php";

        Assert::assertSame("cesa/{$pluginName}", $composer['name'] ?? null, "{$pluginName} composer package name is not aligned.");
        Assert::assertSame('library', $composer['type'] ?? null, "{$pluginName} composer package type should be library.");
        Assert::assertSame([
            [
                'name'  => 'apriansyahrs',
                'email' => 'developer@rizqis.com',
            ],
        ], $composer['authors'] ?? null, "{$pluginName} composer authors are not aligned.");
        Assert::assertContains($providerClass, $composer['extra']['laravel']['providers'] ?? [], "{$pluginName} composer provider is not aligned.");
        Assert::assertSame(cesaExpectedAutoloadPsr4($pluginPath, $studlyName), $composer['autoload']['psr-4'] ?? [], "{$pluginName} source autoload namespaces are not aligned.");
        Assert::assertArrayHasKey("Cesa\\{$studlyName}\\Tests\\", $composer['autoload-dev']['psr-4'] ?? [], "{$pluginName} test namespace is not aligned.");
        Assert::assertFileExists($providerPath, "{$pluginName} service provider is missing.");

        $providerSource = file_get_contents($providerPath);

        Assert::assertStringContainsString("public static string \$name = '{$pluginName}';", $providerSource, "{$pluginName} service provider name is not aligned.");

        if (is_file($pluginClassPath)) {
            $pluginSource = file_get_contents($pluginClassPath);

            Assert::assertStringContainsString("return '{$pluginName}';", $pluginSource, "{$pluginName} Filament plugin id is not aligned.");
            Assert::assertStringContainsString('Package::isPluginInstalled($this->getId())', $pluginSource, "{$pluginName} Filament plugin should be install-aware.");
        }
    }
});

test('cesa service providers declare only real package assets', function (): void {
    foreach (cesaPluginPaths() as $pluginName => $pluginPath) {
        $providerSource = cesaServiceProviderSource($pluginName, $pluginPath);

        Assert::assertSame(
            cesaRouteFiles($pluginPath),
            cesaDeclaredRoutes($providerSource),
            "{$pluginName} route declarations should match non-empty route files.",
        );

        Assert::assertSame(
            cesaMigrationFiles($pluginPath),
            cesaDeclaredMigrations($providerSource),
            "{$pluginName} migration declarations should match migration files.",
        );

        Assert::assertSame(
            cesaConfigFiles($pluginPath),
            cesaDeclaredConfigFiles($pluginName, $providerSource),
            "{$pluginName} config declarations should match config files.",
        );

        Assert::assertSame(
            cesaDirectoryHasFiles("{$pluginPath}/resources/views"),
            str_contains($providerSource, '->hasViews()'),
            "{$pluginName} view declarations should match non-empty view directories.",
        );

        if (cesaDirectoryHasFiles("{$pluginPath}/resources/lang")) {
            Assert::assertStringContainsString('->hasTranslations()', $providerSource, "{$pluginName} has translations but does not declare hasTranslations().");
        }
    }
});

test('cesa installable plugin boot hooks are installation gated', function (): void {
    foreach (cesaPluginPaths() as $pluginName => $pluginPath) {
        $providerSource = cesaServiceProviderSource($pluginName, $pluginPath);

        if (! str_contains($providerSource, 'public function packageBooted(): void') || str_contains($providerSource, '->isCore()')) {
            continue;
        }

        $bootBodyStart = strstr($providerSource, 'public function packageBooted(): void');
        $bootBodyStart = strstr($bootBodyStart, '{');

        Assert::assertStringStartsWith(
            "{\n        if (! (\$this->package->isCore || \$this->package->isInstalled())) {",
            $bootBodyStart,
            "{$pluginName} packageBooted() should gate runtime side effects by install state first.",
        );
    }
});

test('cesa translations keep english and indonesian parity', function (): void {
    foreach (cesaPluginPaths() as $pluginName => $pluginPath) {
        $languagePath = "{$pluginPath}/resources/lang";

        $englishPath = "{$languagePath}/en";
        $indonesianPath = "{$languagePath}/id";

        Assert::assertDirectoryExists($languagePath, "{$pluginName} is missing resources/lang.");
        Assert::assertDirectoryExists($englishPath, "{$pluginName} is missing english translations.");
        Assert::assertDirectoryExists($indonesianPath, "{$pluginName} is missing indonesian translations.");

        $englishFiles = cesaRelativePhpFiles($englishPath);
        $indonesianFiles = cesaRelativePhpFiles($indonesianPath);

        Assert::assertSame($englishFiles, $indonesianFiles, "{$pluginName} translation files differ between en and id.");

        foreach ($englishFiles as $relativeFile) {
            Assert::assertSame(
                cesaTranslationKeys("{$englishPath}/{$relativeFile}"),
                cesaTranslationKeys("{$indonesianPath}/{$relativeFile}"),
                "{$pluginName} translation keys differ for {$relativeFile}.",
            );
        }
    }
});

/**
 * @return array<string, string>
 */
function cesaPluginPaths(): array
{
    $pluginPaths = glob(cesaPluginRoot().'/*', GLOB_ONLYDIR) ?: [];
    $plugins = [];

    foreach ($pluginPaths as $pluginPath) {
        $plugins[basename($pluginPath)] = $pluginPath;
    }

    ksort($plugins);

    return $plugins;
}

function cesaPluginRoot(): string
{
    return dirname(__DIR__, 2).'/plugins/cesa';
}

function cesaStudlyPluginName(string $pluginName): string
{
    return str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $pluginName)));
}

/**
 * @return array<string, string>
 */
function cesaExpectedAutoloadPsr4(string $pluginPath, string $studlyName): array
{
    $autoload = [
        "Cesa\\{$studlyName}\\" => 'src/',
    ];

    if (is_dir("{$pluginPath}/database/factories")) {
        $autoload["Cesa\\{$studlyName}\\Database\\Factories\\"] = 'database/factories/';
    }

    if (is_dir("{$pluginPath}/database/seeders")) {
        $autoload["Cesa\\{$studlyName}\\Database\\Seeders\\"] = 'database/seeders/';
    }

    return $autoload;
}

/**
 * @return array<string, mixed>
 */
function cesaComposerJson(string $pluginPath): array
{
    $composerPath = "{$pluginPath}/composer.json";

    Assert::assertFileExists($composerPath, basename($pluginPath).' composer.json is missing.');

    return json_decode(file_get_contents($composerPath), true, flags: JSON_THROW_ON_ERROR);
}

function cesaServiceProviderSource(string $pluginName, string $pluginPath): string
{
    $providerPath = "{$pluginPath}/src/".cesaStudlyPluginName($pluginName).'ServiceProvider.php';

    Assert::assertFileExists($providerPath, "{$pluginName} service provider is missing.");

    return file_get_contents($providerPath);
}

/**
 * @return array<int, string>
 */
function cesaRouteFiles(string $pluginPath): array
{
    $routeFiles = glob("{$pluginPath}/routes/*.php") ?: [];
    $routes = [];

    foreach ($routeFiles as $routeFile) {
        $contents = preg_replace('/^<\?php\s*/', '', file_get_contents($routeFile));

        if (trim($contents) === '') {
            continue;
        }

        $routes[] = basename($routeFile, '.php');
    }

    sort($routes);

    return $routes;
}

/**
 * @return array<int, string>
 */
function cesaDeclaredRoutes(string $providerSource): array
{
    preg_match_all("/->hasRoute\\('([^']+)'\\)/", $providerSource, $matches);

    $routes = $matches[1] ?? [];
    sort($routes);

    return $routes;
}

/**
 * @return array<int, string>
 */
function cesaMigrationFiles(string $pluginPath): array
{
    $migrations = array_map(
        static fn (string $path): string => basename($path, '.php'),
        glob("{$pluginPath}/database/migrations/*.php") ?: [],
    );

    sort($migrations);

    return $migrations;
}

/**
 * @return array<int, string>
 */
function cesaDeclaredMigrations(string $providerSource): array
{
    if (! preg_match('/->hasMigrations\\(\\s*\\[(.*?)\\]\\s*\\)/s', $providerSource, $matches)) {
        return [];
    }

    preg_match_all("/'([^']+)'/", $matches[1], $migrationMatches);

    $migrations = $migrationMatches[1] ?? [];
    sort($migrations);

    return $migrations;
}

/**
 * @return array<int, string>
 */
function cesaConfigFiles(string $pluginPath): array
{
    $configFiles = array_map(
        static fn (string $path): string => basename($path, '.php'),
        array_filter(
            glob("{$pluginPath}/config/*.php") ?: [],
            static fn (string $path): bool => basename($path) !== 'filament-shield.php',
        ),
    );

    sort($configFiles);

    return $configFiles;
}

/**
 * @return array<int, string>
 */
function cesaDeclaredConfigFiles(string $pluginName, string $providerSource): array
{
    preg_match_all('/->hasConfigFile\\(([^)]*)\\)/', $providerSource, $matches);

    $configFiles = [];

    foreach ($matches[1] ?? [] as $argument) {
        $argument = trim($argument);

        if ($argument === '') {
            $configFiles[] = $pluginName;

            continue;
        }

        if (preg_match("/^'([^']+)'$/", $argument, $configMatch)) {
            $configFiles[] = $configMatch[1];
        }
    }

    sort($configFiles);

    return $configFiles;
}

function cesaDirectoryHasFiles(string $path): bool
{
    return is_dir($path) && count(cesaRelativePhpFiles($path)) > 0;
}

/**
 * @return array<int, string>
 */
function cesaRelativePhpFiles(string $path): array
{
    if (! is_dir($path)) {
        return [];
    }

    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
    $files = [];

    foreach ($iterator as $fileInfo) {
        if (! $fileInfo->isFile() || $fileInfo->getExtension() !== 'php') {
            continue;
        }

        $files[] = ltrim(str_replace($path, '', $fileInfo->getPathname()), DIRECTORY_SEPARATOR);
    }

    sort($files);

    return $files;
}

/**
 * @return array<int, string>
 */
function cesaTranslationKeys(string $filePath): array
{
    $translations = require $filePath;

    Assert::assertIsArray($translations, "{$filePath} must return an array.");

    $keys = cesaFlattenTranslationKeys($translations);
    sort($keys);

    return $keys;
}

/**
 * @param  array<mixed>  $translations
 * @return array<int, string>
 */
function cesaFlattenTranslationKeys(array $translations, string $prefix = ''): array
{
    $keys = [];

    foreach ($translations as $key => $value) {
        $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";
        $keys[] = $path;

        if (is_array($value)) {
            array_push($keys, ...cesaFlattenTranslationKeys($value, $path));
        }
    }

    return $keys;
}
