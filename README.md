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
When /^set the display name of user "([^"]*)" to "([^"]*)"$/
When /^set the email of user "([^"]*)" to "([^"]*)"$/
When sending :verb to ocs :url
When the response should have a status code :code
When fetch field :path from prevous JSON response
When the response should contain the initial state :name with the following values:
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

## Parse response using jq

You can use [jq](https://jqlang.github.io/jq/manual/) expression casting to check a value in a json response body of a request. To do this you will need to install the jq command.

Example:

```gherkin
When set the response to:
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
