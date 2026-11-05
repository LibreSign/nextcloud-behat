#!/usr/bin/env bash
set -euo pipefail

# Verify a local 2.0.0 release candidate. This does not publish a tag.
project_root="$(cd "$(dirname "$0")/.." && pwd)"
consumer_dir="$(mktemp -d "/tmp/nextcloud-behat-consumer-XXXXXXXX")"
trap 'rm -rf -- "$consumer_dir"' EXIT

cd "$consumer_dir"
PROJECT_ROOT="$project_root" php -r '
$manifest = [
    "name" => "libresign/nextcloud-behat-consumer-smoke",
    "repositories" => [
        [
            "type" => "path",
            "url" => getenv("PROJECT_ROOT"),
            "options" => [
                "symlink" => false,
                "versions" => ["libresign/nextcloud-behat" => "2.0.0"],
            ],
        ],
    ],
    "require" => ["libresign/nextcloud-behat" => "^2.0"],
];
file_put_contents("composer.json", json_encode($manifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
'

composer install --no-dev --no-interaction --no-progress --prefer-dist
composer check-platform-reqs

php -r '
require "vendor/autoload.php";
$context = new \Libresign\NextcloudBehat\NextcloudApiContext();
if (!$context instanceof \Behat\Behat\Context\Context) {
    throw new \RuntimeException("Reusable context was not autoloaded");
}
if (!class_exists(\Webmozart\Assert\Assert::class)) {
    throw new \RuntimeException("Standalone assertion dependency is missing");
}
if (class_exists(\PHPUnit\Framework\Assert::class)) {
    throw new \RuntimeException("PHPUnit must not be installed in the runtime dependency closure");
}
'
vendor/bin/behat --version | grep -E '^behat 4\.'
printf '%s\n' 'PHP 8.2 production consumer of local 2.0.0 candidate: PASS'
