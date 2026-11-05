# Nextcloud Behat context

Basic Behat steps for a Nextcloud app

The development branch targets the upcoming **v2.0.0** contract (tracked by issue #124). Until that release is published, an unconstrained Composer install still selects the latest published 1.x release.

## Install

```bash
composer require --dev libresign/nextcloud-behat
```
This package targets PHP 8.2 or later and Behat 4. Behat 4 uses a PHP configuration file rather than YAML. Create `behat.php` in your project's root:

```php
<?php

use Behat\Config\Config;
use Behat\Config\Extension;
use Behat\Config\Profile;
use Behat\Config\Suite;
use PhpBuiltin\Server;

return (new Config())
    ->withProfile((new Profile('default', [
        'autoload' => ['' => '%paths.base%/features/bootstrap'],
    ]))
        ->withSuite((new Suite('default'))
            ->addContext(FeatureContext::class, [
                'parameters' => [
                    'test_password' => '123456',
                    'admin_password' => 'admin',
                ],
            ])
            ->withPaths('%paths.base%/features'))
        ->withExtension(new Extension(Server::class, [
            'verbose' => false,
            'rootDir' => '/var/www/html', // Set this to your application's web root
            'host' => 'localhost',
        ])))
;
```

Create `features/bootstrap/FeatureContext.php` with this content:

```php
<?php

use Libresign\NextcloudBehat\NextcloudApiContext;

class FeatureContext extends NextcloudApiContext {
}
```

Use the Behat 4 default Gherkin compatibility mode; no legacy parsing override is necessary.

### Migrating custom contexts to v2.0.0

Step methods that accept multiline arguments must declare `Behat\Step\DataTable` and/or `Behat\Step\DocString` in their PHP parameter types. Behat 4 uses those declared types to wrap the Gherkin arguments. An untyped parameter receives the legacy AST node instead. `DataTable::asMap()` preserves two-column key/value tables; `asMaps()` preserves header-driven row records; and `asLists()` preserves positional rows, including the first row.

The reusable contexts use `webmozart/assert` at runtime rather than PHPUnit. PHPUnit remains a **development-only** dependency for regression tests. JSON comparisons ignore object-property order but preserve list order and JSON types.

### Validate locally

```bash
composer install
composer run test:unit
vendor/bin/behat --format progress
composer run cs:check
composer run psalm
bash tests/consumer-smoke.sh # validates the unreleased 2.0.0 candidate from a clean PHP 8.2 consumer
```

Then, you can see the available steps:
```bash
vendor/bin/behat -dl
```

```gherkin
Given as user :user
Given user :user exists
Given sending :verb to :url
Given the response should be a JSON array with the following mandatory values
Given guest :guest exists
Given /^set the display name of user "([^"]*)" to "([^"]*)"$/
Given /^set the email of user "([^"]*)" to "([^"]*)"$/
Given sending :verb to ocs :url
Given /^set the custom http header "([^"]*)" with "([^"]*)" as value to next request$/
Given the response should have a status code :code
Given fetch field :path from previous JSON response
Given the response should contain the initial state :name with the following values:
Given the response should contain the initial state :name json that match with:
Given the following :appId app config is set
Given /^run the command "(?P<command>(?:[^"]|\")*)"$/
Given the output of the last command should contain the following text:
Given the output of the last command should be empty
Given /^run the command "(?P<command>(?:[^"]|\")*)" with result code (\d+)$/
Given /^run the bash command "(?P<command>(?:[^"]|\")*)" with result code (\d+)$/
Given create an environment :name with value :value to be used by occ command
Given /^wait for ([0-9]+) (second|seconds)$/
Given /^past ([0-9]+) (second|seconds) since wait step$/
```

## Tips

### Value as string
To send a json value as string, prefix the json string with (string)

**Example**:
```gherkin
Given sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/appname/propertyname"
  | value | (string){"enabled":true} |
```

### Value as array
To send a value as array, you can set a json string and the json string will be converted to array

**Example**:
```gherkin
Given sending "post" to ocs "/apps/libresign/api/v1/request-signature"
  | status | 1 |
  | file   | {"base64":""} |
```

### Step: all steps that run commands

Before the command is executed, the following placeholders will be replaced:

| Placeholder          | Value                  |
| -------------------- | ---------------------- |
| `<appRootDir>`       | your app root dir      |
| `<nextcloudRootDir>` | The Nextcloud root dir |

### Step: `fetch field :path from previous JSON response`

If the json response is an array, you can fetch specific values using this step. The fetched values is stored to be used by other steps.

`:path`: Path is  a selector to retrieves a value from a deeply nested array using "dot" notation:

To the follow json:
```json
{"products":{"desk":{"price":100}}}
```
path need to be: products.desk.price

You also can prefix the path by an alias inside parenthesis:

(price)products.desk.price

The alias `price` could be used in a path or body of a request:
```gherkin
  When set the response to:
    """
    {
      "data": [
        {
          "foo":"bar"
        }
      ]
    }
    """
  And sending "POST" to "/"
  And fetch field "(foo)data.0.foo" from previous JSON response
  # After fetch the field, you can use the value of field like this:
  And sending "POST" to "/?foo=<foo>"
    | field | <data.0.foo> |
```

#### Fetch field using jq

You can assign the return of a jq to your field using a jq like as the follow pattern:

```gherkin
And fetch field "(foo)(jq).value" from previous JSON response
```

This will retrieve a specific value from json response and assign this to your desided field.

## Parse response using jq

You can use [jq](https://jqlang.github.io/jq/manual/) expression casting to check a value in a json response body of a request. To do this you will need to install the jq command.

Example:

```gherkin
Given set the response to:
  """
  {
    "Foo": {
      "Bar": "33"
    }
  }
  """
And sending "POST" to "/"
Then the response should be a JSON array with the following mandatory values
  | key          | value            |
  | Foo          | (jq).Bar == "33" |
  | (jq).Foo     | {"Bar":"33"}     |
  | (jq).Foo     | (jq).Bar == "33" |
  | (jq).Foo.Bar | 33               |
```

## Parse text

If you need to:
- Get values from a request, store and use in other request
- Parse the response of a request

Implement a method `parseText` like the follow code and remember to call parent method.

This methods can works together with `fetch field :path from previous JSON response`
```php
protected function parseText(string $text): string {
  $patterns = [
    '/<SIGN_UUID>/',
    '/<FILE_UUID>/',
  ];
  $replacements = [
    $this->signer['sign_uuid'] ?? null,
    $this->file['uuid'] ?? $this->getFileUuidFromText($text),
  ];
  $text = preg_replace($patterns, $replacements, $text);
  $text = parent::parseText($text);
  return $text;
}
```
For more information about parseText, check the scenario `Test get field from json response`
