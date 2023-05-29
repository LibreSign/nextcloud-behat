# Nextcloud Behat context

Basic Behat steps for a Nextcloud app

## Install

```bash
composer require --dev libresign/nextcloud-behat
```
Create the file `behat.yml`
```yaml
default:
  autoload:
    '': '%paths.base%/features/bootstrap'
  suites:
    default:
      contexts:
        - Libresign\NextcloudBehat\NextcloudApiContext:
            parameters:
              # default value
              test_password: 123456
              # default value
              admin_password: admin
      # Only necessary if you want to have a different features folder
      paths:
        - '%paths.base%/features'
  extensions:
    # Use this extension to start the server
    PhpBuiltin\Server:
      verbose: false
      rootDir: /var/www/html
      host: localhost
```
Create the file `tests/features/bootstrap/FeatureContext.php` with this content:
```php
<?php

use Libresign\NextcloudBehat;

class FeatureContext extends NextcloudApiContext {

}
```

Then, now you can see all available steps:
```bash
vendor/bin/behat -dl
```

```gherkin
When as user :user
When user :user exists
When sending :verb to :url
When the response should be a JSON array with the following mandatory values
When the response should contain the initial state :name with the following values:
When /^set the email of user "([^"]*)" to "([^"]*)"$/
When sending :verb to ocs :url
When the response should have a status code :code
When the following :appId app config is set
```

## Tips

### Value as string
To send a json value as string, prefix the json string with (string)

**Example**:
```gherkin
When sending "post" to ocs "/apps/provisioning_api/api/v1/config/apps/appname/propertyname"
  | value | (string){"enabled":true} |
```

### Value as array
To send a value as array, you can set a json string and the json string will be converted to array

**Example**:
```gherkin
When sending "post" to ocs "/apps/libresign/api/v1/request-signature"
  | status | 1 |
  | file | {"base64":""} |
```

By this way you will receive on your controller method 2 values, status as integer and file as array.

## Parse initial state
If you need to parse the initial state to use placeholder or get any value from current initial state, implement a method `parseText` like this:
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
  return $text;
}
```
