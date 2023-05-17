# HeyRestApi

A sample class to communicate with the heyrecruit rest API.

## Table of Contents

- [Installation](#installation)
- [Usage](#usage)
- [Methods](#methods)
- [License](#license)

## Installation

The SCOPE Recruiting PHP SDK can be installed with [Composer](https://getcomposer.org/). Run this command:

```sh
composer require werbeagentur_artrevolver/scope_php_sdk
```
OR

Clone the repository and include the `HeyRestApi.php` file in your project.

```php
require_once 'path/to/HeyRestApi.php';
```

## Usage

Initialize a new instance of the HeyRestApi class by providing the configuration settings:

```php
$config = [
    'SCOPE_URL' => 'https://example.com',
    'SCOPE_CLIENT_ID' => 'your-client-id',
    'SCOPE_CLIENT_SECRET' => 'your-client-secret',
    'GA_TRACKING' => true, // optional
];

$api = new HeyRestApi($config);
```



