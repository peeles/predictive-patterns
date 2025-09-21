<?php

$basePath = dirname(__DIR__);
$envPath = $basePath . DIRECTORY_SEPARATOR . '.env';
$examplePath = $basePath . DIRECTORY_SEPARATOR . '.env.example';

if (!file_exists($envPath) && file_exists($examplePath)) {
    copy($examplePath, $envPath);
}

if (!file_exists($envPath)) {
    exit(0);
}

$envContents = file($envPath, FILE_IGNORE_NEW_LINES);
if ($envContents === false) {
    exit(0);
}

$needsKey = true;
$foundKey = false;

foreach ($envContents as $index => $line) {
    if (preg_match('/^\s*APP_KEY\s*=/', $line) !== 1) {
        continue;
    }

    $foundKey = true;

    $value = substr($line, strpos($line, '=') + 1);
    if ($value === false) {
        break;
    }

    $value = trim($value);
    $value = trim($value, "'\"");

    if ($value !== '' && strcasecmp($value, 'null') !== 0) {
        $needsKey = false;
    }

    break;
}

if (!$foundKey) {
    $envContents[] = 'APP_KEY=';
    file_put_contents($envPath, implode(PHP_EOL, $envContents) . PHP_EOL);
}

if (!$needsKey) {
    exit(0);
}

$artisan = $basePath . DIRECTORY_SEPARATOR . 'artisan';
if (!file_exists($artisan)) {
    exit(0);
}

$autoload = $basePath . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';
if (!file_exists($autoload)) {
    exit(0);
}

$command = sprintf('php %s key:generate --ansi', escapeshellarg($artisan));
passthru($command, $status);
exit($status);
