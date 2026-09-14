<?php

use Illuminate\Container\Container;
use Illuminate\Foundation\Application;

it('uses secure S3 TLS defaults and honors explicit configuration', function (?string $verifyEnvironment, bool|string $expectedVerification): void {
    $originalContainer = Container::getInstance();
    $originalEnvironment = $_ENV;
    $originalServer = $_SERVER;
    $originalProcessEnvironment = getenv('AWS_VERIFY');

    try {
        new Application(dirname(__DIR__, 2));

        unset($_ENV['AWS_VERIFY'], $_SERVER['AWS_VERIFY']);
        putenv('AWS_VERIFY');

        if ($verifyEnvironment !== null) {
            $_ENV['AWS_VERIFY'] = $verifyEnvironment;
            $_SERVER['AWS_VERIFY'] = $verifyEnvironment;
            putenv('AWS_VERIFY='.$verifyEnvironment);
        }

        $filesystems = require dirname(__DIR__, 2).'/config/filesystems.php';

        expect($filesystems['disks']['s3']['http']['verify'])->toBe($expectedVerification);
    } finally {
        $_ENV = $originalEnvironment;
        $_SERVER = $originalServer;
        putenv($originalProcessEnvironment === false ? 'AWS_VERIFY' : 'AWS_VERIFY='.$originalProcessEnvironment);
        Container::setInstance($originalContainer);
    }
})->with([
    'unset defaults to certificate verification' => [null, true],
    'explicitly enabled verification'            => ['true', true],
    'explicitly disabled verification'           => ['false', false],
    'custom CA bundle'                           => ['/etc/ssl/custom-ca.pem', '/etc/ssl/custom-ca.pem'],
]);
