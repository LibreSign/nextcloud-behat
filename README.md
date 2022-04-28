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
        - Libresign\NextcloudBehat\NextcloudContext:
            parameters:
              # default value
              test_password: 123456
              # default value
              admin_password: admin
      paths:
        - '%paths.base%/features'
  extensions:
    PhpBuiltin\Server:
      verbose: false
      rootDir: /var/www/html
      host: localhost
```
Create the file `tests/features/bootstrap/FeatureContext.php` with this content:
```php
<?php

use Libresign\NextcloudBehat;

class FeatureContext extends NextcloudContext {

}
```

Then, now you can see all available steps:
```bash
vendor/bin/behat -dl