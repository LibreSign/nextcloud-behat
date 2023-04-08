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
