# Silverstripe Fluent Extra Table

This module is extended from [Fluent](https://github.com/tractorcow/silverstripe-fluent).

## Store Locale Content In Extra Tables

Fluent module is awesome and easy to use. However, all extra locale columns are stored in the same data object table. It could cause MYSQL problems if there are too many columns defined in one table. For example, [Row size too large](https://github.com/tractorcow/silverstripe-fluent/issues?utf8=%E2%9C%93&q=row%20size) error. 

This module extends Fluent feature and locale data are stored in separated table with locale name suffix.

## Install
```bash
composer require internetrix/silverstripe-fluent-extra-table:1.0.0
```

## Translatable Versioned Dataobjects
Put code below for tranlsateable versioned data objects.
```
Fluent:
  VersionedFluentDataObjects:
    - <DataObject Name>
```
