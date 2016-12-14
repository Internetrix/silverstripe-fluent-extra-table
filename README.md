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
## Current issue with Versioned
Need to implement function allVersions() as below in Page.php to avoid error when clicking history on a page.

	public function allVersions($filter = "", $sort = "", $limit = "", $join = "", $having = "") {
		// Make sure the table names are not postfixed (e.g. _Live)
		$oldMode = Versioned::get_reading_mode();
		Versioned::reading_stage('Stage');
	
		$list = DataObject::get(get_class($this), $filter, $sort, $join, $limit);
		if($having) $having = $list->having($having);
	
		$query = $list->dataQuery()->query();
	
		foreach($query->getFrom() as $table => $tableJoin) {
			if(is_string($tableJoin) && $tableJoin[0] == '"') {
				$baseTable = str_replace('"','',$tableJoin);
			} elseif(is_string($tableJoin) && substr($tableJoin,0,5) != 'INNER') {
				$query->setFrom(array(
						$table => "LEFT JOIN \"$table\" ON \"$table\".\"RecordID\"=\"{$baseTable}_versions\".\"RecordID\""
						. " AND \"$table\".\"Version\" = \"{$baseTable}_versions\".\"Version\""
				));
			}
			$locale = Fluent::current_locale();
	
			if(strpos($table, $locale) !== false){
				$table = str_replace('_' . $locale,'',$table);
				$query->renameTable($table, $table . '_versions' . '_' . $locale);
			}else{
				$query->renameTable($table, $table . '_versions');
			}
	
		}
	
		// Add all <basetable>_versions columns
		foreach(Config::inst()->get('Versioned', 'db_for_versions_table') as $name => $type) {
			$query->selectField(sprintf('"%s_versions"."%s"', $baseTable, $name), $name);
		}
	
		$query->addWhere(array(
				"\"{$baseTable}_versions\".\"RecordID\" = ?" => $this->ID
		));
		$query->setOrderBy(($sort) ? $sort
				: "\"{$baseTable}_versions\".\"LastEdited\" DESC, \"{$baseTable}_versions\".\"Version\" DESC");
	
		$records = $query->execute();
		$versions = new ArrayList();
	
		foreach($records as $record) {
			$versions->push(new Versioned_Version($record));
		}
	
		Versioned::set_reading_mode($oldMode);
		return $versions;
	}
