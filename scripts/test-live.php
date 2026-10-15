<?php

// Explicit opt-in runner. Never load real credentials into the ordinary test suite.
require __DIR__.'/../vendor/autoload.php';
Dotenv\Dotenv::createUnsafeImmutable(dirname(__DIR__))->load();
$provider = getenv('ASSISTANT_PROVIDER') ?: 'openai';
if (!in_array($provider, ['openai', 'gemini'], true)) {
    fwrite(STDERR, "Unsupported ASSISTANT_PROVIDER.\n");
    exit(1);
}
$keyName = $provider === 'gemini' ? 'GEMINI_API_KEY' : 'OPENAI_API_KEY';
if (!getenv($keyName)) {
    fwrite(STDERR, $keyName." is missing.\n");
    exit(1);
}
putenv('LIVE_AI=1');
$argv = [__FILE__, '--filter', 'test_live_ai', '--testdox', '--log-junit', '.runtime/live-'.$provider.'-results.xml'];
$_SERVER['argv'] = $argv;
$_SERVER['argc'] = count($argv);
exit((new PHPUnit\TextUI\Application)->run($argv));
