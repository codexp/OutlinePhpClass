# OutlinePhpClass

The [OutlinePhpClass MediaWiki extension](https://www.mediawiki.org/wiki/Extension:OutlinePhpClass), outlines a php class (displays signature of the class only).

## Installation

Clone or download OutlinePhpClass code and place it inside your MediaWiki extensions directory.

Then simply include the entry point in your `LocalSettings.php` file:

```php
require_once( "$IP/extensions/OutlinePhpClass/OutlinePhpClass.php" );
```

## Configuration

*Mandatory*: Array of absolute paths or files to be allowed for reading by this extension.

    $opcWhitelist

*Optional*: Array of absolute paths or files to be blocked for reading by this extension.

    $opcBlacklist


**Note**: Either, in blacklist or whitelist, expanders like * or ? do not work!

## Usage

You can place a special function call like this:

    {{#OutlinePhpClass:/whitelisted/phpsource/path/file.php}}

that's it!

## Authors

OutlinePhpClass has been written by
[Eugen Wesseloh](https://www.mediawiki.org/wiki/User:Eugen_Wesseloh).

## Release notes

### Version 0.0.1 (16.06.2014)

* first release
