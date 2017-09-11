<?php
/**
 * @package fluent-extra
* @author Jason Zhang <jason.zhang@internetrix.com.au>
*/
class ExtraTable_FluentExtension extends FluentExtension
{
    /**
     * Override
     *
     * Determines the fields to translate on the given class
     *
     * @return array List of field names and data types
     */
    public static function translated_fields_for($class)
    {
        if (isset(self::$translated_fields_for_cache[$class])) {
            return self::$translated_fields_for_cache[$class];
        }
        return self::$translated_fields_for_cache[$class] = self::without_fluent_fields(function () use ($class) {
            $db = DataObject::custom_database_fields($class);
            $filter = Config::inst()->get($class, 'translate', Config::UNINHERITED);
            $filterIn = Config::inst()->get($class, 'translate_append', Config::UNINHERITED);
            if( $filter === 'none' ){
                if( $filterIn === 'none' ) {
                    return array();
                }
            }
            // Data and field filters
            $fieldsInclude = Fluent::config()->field_include;
            $fieldsExclude = Fluent::config()->field_exclude;
            $dataInclude = Fluent::config()->data_include;
            $dataExclude = Fluent::config()->data_exclude;

            // filter out DB
            if ($db) {
                foreach ($db as $field => $type) {
                    if (!empty($filter)) {
                        // If given an explicit field name filter, then remove non-presented fields
                        if ( !in_array($field, $filter) ) {
                            unset($db[$field]);
                        }
                    } elseif( !empty($filterIn) && in_array($field, $filterIn )) {
                        // keep this puppy
                    } else {
                        // Without a name filter then check against each filter type
                        if (($fieldsInclude && !Fluent::any_match($field, $fieldsInclude))
                            || ($fieldsExclude && Fluent::any_match($field, $fieldsExclude))
                            || ($dataInclude && !Fluent::any_match($type, $dataInclude))
                            || ($dataExclude && Fluent::any_match($type, $dataExclude))
                        ) {
                            unset($db[$field]);
                        }
                    }
                }
            }

            return $db;
        });
    }

    /**
     * Override
     *
     * Get all database tables in the class ancestry and their respective
     * translatable fields
     *
     * @return array
     */
    protected function getTranslatedTables()
    {
        $includedTables = parent::getTranslatedTables();
         
        if( ! empty($includedTables)){
            foreach ($includedTables as $class => $translatedFields){
                // Make sure Versioned tables have fluent support.
                if(Object::has_extension($class, 'Versioned')){
                    $includedTables["{$class}_versions"] = $translatedFields;
                    $includedTables["{$class}_Live"] = $translatedFields;
                }
            }
        }
         
        return $includedTables;
    }


    /**
     * Override - stop generating locales db table columns.
     */
    public static function get_extra_config($class, $extension, $args)
    {
        self::$disable_fluent_fields = true;
        return array();
    }


    /**
     * Generates a select fragment based on a field with a fallback
     *
     * @param string $class Table/Class name
     * @param string $select Column to select from
     * @param string $fallback Column to fallback to if $select is empty
     * @return string Select fragment
     */
    protected function localiseTableSelect($class, $select, $fallback, $locale)
    {
        return "CASE COALESCE(CAST(\"{$class}_{$locale}\".\"{$select}\" AS CHAR), '')
        WHEN '' THEN \"{$class}\".\"{$fallback}\"
        WHEN '0' THEN \"{$class}\".\"{$fallback}\"
        ELSE \"{$class}_{$locale}\".\"{$select}\" END";
    }
    
    /**
     * Replaces all columns in the given condition with any localised
     *
     * @param string $condition Condition SQL string
     * @param array $includedTables
     * @param string $locale Locale to localise to
     * @return string $condition parameter with column names replaced
     */
    protected function localiseFilterCondition($condition, $includedTables, $locale)
    {
        foreach ($includedTables as $table => $columns) {
            foreach ($columns as $column) {
                $columnLocalised = Fluent::db_field_for_locale($column, $locale);
                $identifier = "\"{$table}\".\"{$column}\"";
                $identifierLocalised = "\"{$table}_{$locale}\".\"{$columnLocalised}\"";
                $condition = preg_replace("/".preg_quote($identifier, '/')."/", $identifierLocalised, $condition);
            }
        }
        return $condition;
    }

    /**
     * Left join locale tables to SQLQuery.
     *
     * @param SQLQuery $query
     * @param string $locale
     * @param string $includedTables
     */
    protected function localiseJoin(SQLQuery &$query, $locale, $includedTables)
    {
        $fromArray 	= $query->getFrom();
         
        $isLiveMod	= ( Versioned::current_stage() == 'Live' ) ? true : false;
        
        $default = Fluent::default_locale();

        if(count($fromArray)){
            foreach ($fromArray as $table => $config){
                // get DB table name
                if(is_array($config) && isset($config['table']) && $config['table']){
                    $primaryTable 	= $config['table'];
                }else{
                    $primaryTable 	= $table;
                }
                 
                //check if this table require fluent translation
                if( ! isset($includedTables[$primaryTable])){
                    continue;
                }
                 
                // join locale table
                $localeTable = $primaryTable . '_' . $locale;
                if(DB::get_schema()->hasTable($localeTable) && ! isset($fromArray[$localeTable])){
                    $query->addLeftJoin($localeTable, "\"{$primaryTable}\".\"ID\" = \"$localeTable\".\"ID\"");
                }
                
                //check version mode for locale table
                $baseLiveTableName = $primaryTable . '_Live';
                if($isLiveMod && isset($includedTables[$baseLiveTableName])){
                    $query->renameTable($localeTable, $baseLiveTableName . '_' . $locale);
                }
                
                // join default table
                $defaultTable = $primaryTable . '_' . $default;
                if(DB::get_schema()->hasTable($defaultTable) && ! isset($fromArray[$defaultTable])){
                    $query->addLeftJoin($defaultTable, "\"{$primaryTable}\".\"ID\" = \"$defaultTable\".\"ID\"");
                }
                 
                //check version mode for default table
                $baseLiveTableName = $primaryTable . '_Live';
                if($isLiveMod && isset($includedTables[$baseLiveTableName])){
                    $query->renameTable($defaultTable, $baseLiveTableName . '_' . $default);
                }
            }
        }
    }
    
    /**
     * Override
     *
     * @see FluentExtension::augmentSQL()
     */
    public function augmentSQL(SQLQuery &$query, DataQuery &$dataQuery = null)
    {
        // Get locale and translation zone to use
        $default = Fluent::default_locale();
        $locale = $dataQuery->getQueryParam('Fluent.Locale') ?: Fluent::current_locale();
    
        // Get all tables to translate fields for, and their respective field names
        $includedTables = $this->getTranslatedTables();
    
        // Join locale table
        $this->localiseJoin($query, $locale, $includedTables);
    
        // Iterate through each select clause, replacing each with the translated version
        foreach ($query->getSelect() as $alias => $select) {
    
            // Skip fields without table context
            if (!preg_match('/^"(?<class>[\w\\\\]+)"\."(?<field>\w+)"$/i', $select, $matches)) {
                continue;
            }
    
            $class = $matches['class'];
            $field = $matches['field'];
    
            // If this table doesn't have translated fields then skip
            if (empty($includedTables[$class])) {
                continue;
            }
    
            // If this field shouldn't be translated, skip
            if (!in_array($field, $includedTables[$class])) {
                continue;
            }
            $isFieldNullable = $this->isFieldNullable($field);
    
            // Select visible field from translated fields (Title_fr_FR || Title => Title)
            $translatedField = Fluent::db_field_for_locale($field, $locale);
            if ($isFieldNullable) {
                // Table_locale.Field_locale => Table.Field
                $expression = "\"{$class}_{$locale}\".\"{$translatedField}\"";
            } else {
                // Table_locale.Field_locale || Table.Field => Table.Field
                $expression = $this->localiseTableSelect($class, $translatedField, $field, $locale);
            }
            $query->selectField($expression, $alias);
    
            // At the same time, rewrite the selector for the default field to make sure that
            // (in the case it is blank, which happens if installing fluent for the first time)
            // that it also populated from the root field.
            $defaultField = Fluent::db_field_for_locale($field, $default);
            if ($isFieldNullable) {
                // Force Table_default.Field_default => Table_default.Field_default
                $defaultExpression = "\"{$class}_{$default}\".\"{$defaultField}\"";
            } else {
                // Table_default.Field_default || Table.Field => Table.Field_default
                $defaultExpression = $this->localiseTableSelect($class, $defaultField, $field, $default);
            }
            $query->selectField($defaultExpression, $defaultField);
        }
    
        // Rewrite where conditions with parameterised query (3.2 +)
        $where = $query
            ->toAppropriateExpression()
            ->getWhere();
        foreach ($where as $index => $condition) {
            // Extract parameters from condition
            if ($condition instanceof SQLConditionGroup) {
                $parameters = array();
                $predicate = $condition->conditionSQL($parameters);
            } else {
                $parameters = array_values(reset($condition));
                $predicate = key($condition);
            }
    
            // Find the first localised column that this condition matches.
            // Use this as the basis of determining how to rewrite this query
            $filterColumnArray = $this->detectFilterColumn($predicate, $includedTables);
            if (empty($filterColumnArray)) {
                continue;
            }
            list($table, $column) = $filterColumnArray;
            $filterColumn = "\"{$table}_{$locale}\".\"".Fluent::db_field_for_locale($column, $locale)."\"";
    
            // Duplicate the condition with all localisable fields replaced
            $localisedPredicate = $this->localiseFilterCondition($predicate, $includedTables, $locale);
            if ($localisedPredicate === $predicate) {
                continue;
            }
    
            // Determine rewrite behaviour based on nullability of the "root" column in this condition
            // This behaviour in imprecise, as the condition may contain filters with mixed nullability
            // but it is a good approximation.
            // For better accuracy of rewrite, ensure that each condition in a query is a separate where.
            if ($this->isFieldNullable($column)) {
                // If this field is nullable, then the condition is a simple rewrite of Table.Field => Table.Field_locale
                $where[$index] = array(
                    $localisedPredicate => $parameters
                );
            } else {
                // Generate new condition that conditionally executes one of the two conditions
                // depending on field nullability.
                // If the filterColumn is null or empty, then it's considered untranslated, and
                // thus the query should continue running on the default column unimpeded.
                $castColumn = "COALESCE(CAST($filterColumn AS CHAR), '')";
                $newPredicate = "
                    ($castColumn != '' AND $castColumn != '0' AND ($localisedPredicate))
                    OR (
                    ($castColumn = '' OR $castColumn = '0') AND ($predicate)
                )";
                // Duplicate this condition with parameters duplicated
                $where[$index] = array(
                    $newPredicate => array_merge($parameters, $parameters)
                );
            }
        }
        $query->setWhere($where);
    
        // Augment search if applicable
        if ($adapter = Fluent::search_adapter()) {
            $adapter->augmentSearch($query, $dataQuery);
        }
    }

    public function augmentWrite(&$manipulation)
    {
    
        // Bypass augment write if requested
        if (!self::$_enable_write_augmentation) {
            return;
        }
    
        // Get locale and translation zone to use
        $locale = $this->owner->getSourceQueryParam('Fluent.Locale') ?: Fluent::current_locale();
        $defaultLocale = Fluent::default_locale();
    
        // Get all tables to translate fields for, and their respective field names
        $includedTables = $this->getTranslatedTables();
    
        // Versioned fields
        $versionFields = array("RecordID", "Version");
    
        // Iterate through each select clause, replacing each with the translated version
        foreach ($manipulation as $class => $updates) {
             
            $localeTable = $class . "_" . $locale;
             
            $fluentFieldNames = array();
             
            $fluentFields = array();
            $fluentFields[$localeTable] = $updates;
    
            // If this table doesn't have translated fields then skip
            if (empty($includedTables[$class])) {
                continue;
            }
    
            foreach ($includedTables[$class] as $field) {
    
                //put all fluent field names of $class into array $fluentFieldNames
                $updateField = Fluent::db_field_for_locale($field, $locale);
                $fluentFieldNames[] = $updateField;
    
                // Skip translated field if not updated in this request
                if (empty($updates['fields']) || !array_key_exists($field, $updates['fields'])) {
                    continue;
                }
    
                // Copy the updated value to the locale specific table.field
                $fluentFields[$localeTable]['fields'][$updateField] = $updates['fields'][$field];
    
                // If not on the default locale, write the stored default field back to the main field
                // (if Title_en_NZ then Title_en_NZ => Title)
                // If the default subfield has no value, then save using the current locale
                if ($locale !== $defaultLocale) {
                    $defaultField = Fluent::db_field_for_locale($field, $defaultLocale);
    
                    // Write default value back if a value exists,
                    // but if this field can be nullable, write it back even if empty.
                    if (!empty($updates['fields'][$defaultField]) || $this->isFieldNullable($field)) {
                        $updates['fields'][$field] = isset($updates['fields'][$defaultField]) ?: null;
                    } else {
                        unset($updates['fields'][$field]);
                    }
                }
            }
    
            // Save back modifications to the manipulation
            $manipulation[$class] = $updates;
    
            // Save locale data.
            if(isset($fluentFields[$localeTable]['fields']) && count($fluentFields[$localeTable]['fields'])){
                if(count($fluentFieldNames)){
                    foreach ($fluentFields[$localeTable]['fields'] as $fieldName => $fieldValue){
                        if( ! in_array($fieldName, $fluentFieldNames)){
                            //skip non-locale fields
                            unset($fluentFields[$localeTable]['fields'][$fieldName]);
                        }
                    }
                }
                 
                $manipulation[$localeTable] = $fluentFields[$localeTable];
                 
                //check *_versions table. if this is Versioned table, copy 'Version' and 'RecordID' to locale version table
                if(stripos($class, '_versions') !== false && count($versionFields)){
                    foreach ($versionFields as $versionFieldName){
                        if(isset($manipulation[$class]['fields'][$versionFieldName])) {
                            $manipulation[$localeTable]['fields'][$versionFieldName] = $manipulation[$class]['fields'][$versionFieldName];
                        }
                    }
                }
            }
        }
    }
    
    public function onAfterDelete() {

        $class = $this->owner->class;

        $includedTables = $this->getTranslatedTables();

        if(empty($includedTables[$class])){
            return;
        }

        if($this->owner->hasExtension('Versioned')){
            //has Versioned ext. check mode and current locale.
            $mode 	= (Versioned::current_stage() == 'Live') ? '_Live' : '';
            	
            $locale = Fluent::current_locale();
            	
            $localeSuffix = $locale ? '_' . $locale : '';
            	
            DB::prepared_query(
                "DELETE FROM \"{$class}{$mode}{$localeSuffix}\" WHERE \"ID\" = ?",
                array($this->owner->ID)
            );
        }else{
            // no Versioned ext. delete all records from all locale tables
            foreach (Fluent::locales() as $locale) {
                //delete records from all locale tables.
                $localeTable = $class . '_' . $locale;
                	
                DB::prepared_query(
                    "DELETE FROM \"{$localeTable}\" WHERE \"ID\" = ?",
                    array($this->owner->ID)
                );
            }
        }
    }
    
    public function updateCMSFields(FieldList $fields)
    {
        // perform parent modifications
        parent::updateCMSFields($fields);
        
        // fix modified label/title of translated fields
        $translated = $this->getTranslatedTables();
        foreach ($translated as $table => $translatedFields) {
            foreach ($translatedFields as $translatedField) {
        
                // Find field matching this translated field
                // If the translated field has an ID suffix also check for the non-suffixed version
                // E.g. UploadField()
                $field = $fields->dataFieldByName($translatedField);
                if (!$field && preg_match('/^(?<field>\w+)ID$/', $translatedField, $matches)) {
                    $field = $fields->dataFieldByName($matches['field']);
                }
        
                // fix classes/labels of reset link
                if ($field && $field->hasClass('LocalisedField')) {
                    if (!$field->hasClass('UpdatedLocalisedField')) {
                        
                        $locale = Fluent::current_locale();
                        $isModified = Fluent_Extension::isTableFieldModified($this->owner, $field, $locale);
                        
                        if ($isModified) {
                            $dom = new DOMDocument();
                            $dom->loadHTML($field->Title());
                            $spans = $dom->getElementsByTagName('span');
                            if (isset($spans[0])) {
                                $classes = $spans[0]->getAttribute('class');
                                if (strpos($classes, 'fluent-modified-value') === false) {
                                    // update classes
                                    $classes .= ' fluent-modified-value';
                                    $spans[0]->removeAttribute('class');
                                    $spans[0]->setAttribute('class', $classes);
                                    // updat etitle
                                    $spans[0]->removeAttribute('title');
                                    $spans[0]->setAttribute('title', 'Modified from default locale value - click to reset');
                                }
                            }
                            $body = $dom->getElementsByTagName('body')->item(0);
                            $title = '';
                            foreach ($body->childNodes as $child){
                                $title .= $dom->saveHTML($child);
                            }
                            $field->setTitle($title);
                        }
                        
                        $field->addExtraClass('UpdatedLocalisedField');
                    }
                }
            }
        }
    }

    public static function ConfigVersionedDataObject(){
        //remove old FluentExtension and FluentSiteTree extensions.
        SiteTree::remove_extension('FluentSiteTree');
        SiteConfig::remove_extension('FluentExtension');
         
        //Fix versioned dataobjects.
        // 1. SiteTree
        self::ChangeExtensionOrder('SiteTree', 'ExtraTable_FluentSiteTree');
         
        // 2. User defined DataObject has Versioned extension.
        $list = Config::inst()->get('Fluent', 'VersionedFluentDataObjects');
        if(is_array($list) && count($list)){
            foreach ($list as $dataObjectName){
                if($dataObjectName::has_extension('ExtraTable_FluentExtension') && $dataObjectName::has_extension('Versioned')){
                    self::ChangeExtensionOrder($dataObjectName);
                }
            }
        }
    }

    /**
     * have to move fluent related extension to bottom of ext list to make it work for Versioned extension.
     *
     * @TODO find a better way to define extensions order....
     *
     * e.g. SiteTree extension order need to be like that. 'Versioned' should be above 'FluentSiteTree' or 'ExtraTable_FluentExtension'
     *
     * 1 => string 'Hierarchy'
     * 2 => string 'Versioned('Stage', 'Live')'
     * 3 => string 'SiteTreeLinkTracking'
     * 4 => string 'ExtraTable_FluentSiteTree'
     * 
     * replicate the following setting in your mysite/_config.php if you add ExtraTable_FluentExtension for Versioned DataObject like SiteTree.
     *
     * Don't worry about sub classes of SiteTree or Versioned DataObject.
     *
     */
    public static function ChangeExtensionOrder($class, $extension = 'ExtraTable_FluentExtension'){
        $class::remove_extension($extension);
         
        $data = Config::inst()->get($class, 'extensions');
         
        $data[] = $extension;
         
        Config::inst()->remove($class, 'extensions');
        Config::inst()->update($class, 'extensions', $data);
    }

    public function augmentDatabase(){
        $includedTables = $this->getTranslatedTables();

        if(isset($includedTables[$this->owner->class])){
            foreach (Fluent::locales() as $locale) {
                //loop all locale. create extra table for each locale.
                $this->owner->requireExtraTable($locale, $includedTables[$this->owner->class]);
            }
        }
    }

    public function requireExtraTable($locale, $includedFields) {
        $suffix = $locale;
         
        // Only build the table if we've actually got fields
        $fields 	= DataObject::database_fields($this->owner->class);
        $extensions = $this->owner->database_extensions($this->owner->class);
        $indexes 	= $this->owner->databaseIndexes();

        if($fields) {
            $fields 	= $this->generateLocaleDBFields($fields, $includedFields, $locale);
            $indexes 	= $this->generateLocaleIndexesFields($indexes, $fields, $locale);

            $hasAutoIncPK = ($this->owner->class == ClassInfo::baseDataClass($this->owner->class));
            DB::require_table("{$this->owner->class}_{$suffix}", $fields, $indexes, $hasAutoIncPK, $this->owner->stat('create_table_options'), $extensions);
        } else {
            DB::dont_require_table("{$this->owner->class}_{$suffix}");
        }
         
        //check if need Versions extension table
        if($this->owner->hasExtension('Versioned')){

            $this->owner->requireExtraVersionedTable($this->owner->class, $includedFields, $locale);

        }
    }

    public function generateLocaleDBFields($baseFields, $includedFields, $locale){
        // Generate $db for class
        $db = array();
        if ($baseFields) {
            foreach ($baseFields as $field => $type) {
                if(is_array($includedFields) && ! in_array($field, $includedFields)){
                    continue;
                }
                 
                // Transform has_one relations into basic int fields to prevent interference with ORM
                if ($type === 'ForeignKey') {
                    $type = 'Int';
                }
                $translatedName = Fluent::db_field_for_locale($field, $locale);
                $db[$translatedName] = $type;
            }
        }

        return empty($db) ? null : $db;
    }

    public function generateLocaleIndexesFields($baseIndexes, $baseFields, $locale){
        $indexes = array();
        if ($baseIndexes) {
            foreach ($baseIndexes as $baseIndex => $baseSpec) {
                if ($baseSpec === 1 || $baseSpec === true) {
                    if (isset($baseFields[$baseIndex])) {
                        // Single field is translated, so add multiple indexes for each locale
                        // Transform has_one relations into basic int fields to prevent interference with ORM
                        $translatedName = Fluent::db_field_for_locale($baseIndex, $locale);
                        $indexes[$translatedName] = $baseSpec;
                    }
                } else {
                    // Check format of spec
                    $baseSpec = self::parse_index_spec($baseIndex, $baseSpec);
                     
                    // Check if columns overlap with translated
                    $columns = self::explode_column_string($baseSpec['value']);
                    $translatedColumns = array_intersect(array_keys($baseFields), $columns);
                    if ($translatedColumns) {
                        // Generate locale specific version of this index
                        $newColumns = array();
                        foreach ($columns as $column) {
                            $newColumns[] = isset($baseFields[$column])
                            ? Fluent::db_field_for_locale($column, $locale)
                            : $column;
                        }
                         
                        // Inject new columns and save
                        $newSpec = array_merge($baseSpec, array(
                            'name' => Fluent::db_field_for_locale($baseIndex, $locale),
                            'value' => self::implode_column_list($newColumns)
                        ));
                        $indexes[$newSpec['name']] = $newSpec;
                    }
                }
            }
        }
         
        return empty($indexes) ? null : $indexes;
    }


    public function requireExtraVersionedTable($classTable, $includedFields, $locale){
         
        $versionExtObj = $this->owner->getExtensionInstance('Versioned'); /* @var $versionExtObj Versioned */
         
        $this->stages = $versionExtObj->getVersionedStages();
        $this->defaultStage = $versionExtObj->getDefaultStage();
         
        /**
         * ================================================================
         * Most of following codes are copied from Versioned->augmentDatabase().
         * Changed some codes.
         * ================================================================
         */
         
        $isRootClass = ($this->owner->class == ClassInfo::baseDataClass($this->owner->class));

        // Build a list of suffixes whose tables need versioning
        $allSuffixes 	= array();
        $allSuffixes[] 	= $locale;

        // Add the default table with an empty suffix to the list (table name = class name)
        array_push($allSuffixes,'');

        foreach ($allSuffixes as $key => $suffix) {
            // check that this is a valid suffix
            if (!is_int($key) || ! $suffix) continue;

            $table = $classTable;

            $fields = DataObject::database_fields($this->owner->class);
            	
            $fields 	= $this->generateLocaleDBFields($fields, $includedFields, $suffix);
            	
            if($fields) {
                $options = Config::inst()->get($this->owner->class, 'create_table_options', Config::FIRST_SET);

                $indexes 	= $this->owner->databaseIndexes();
                $indexes 	= $this->generateLocaleIndexesFields($indexes, $fields, $suffix);

                // Create tables for other stages
                foreach($this->stages as $stage) {
                    // Extra tables for _Live, etc.
                    // Change unique indexes to 'index'.  Versioned tables may run into unique indexing difficulties
                    // otherwise.
                    if($indexes && count($indexes)) $indexes = $this->uniqueToIndex($indexes);
                    	
                    if($stage != $this->defaultStage) {
                        DB::require_table("{$table}_{$stage}_{$suffix}", $fields, $indexes, false, $options);
                    }

                    // Version fields on each root table (including Stage)
                    /*
                    if($isRootClass) {
                    $stageTable = ($stage == $this->defaultStage) ? $table : "{$table}_$stage";
                    $parts=Array('datatype'=>'int', 'precision'=>11, 'null'=>'not null', 'default'=>(int)0);
                    $values=Array('type'=>'int', 'parts'=>$parts);
                    DB::requireField($stageTable, 'Version', $values);
                    }
                    */
                }

                if($isRootClass) {
                    // Create table for all versions
                    $versionFields = array_merge(
                        Config::inst()->get('Versioned', 'db_for_versions_table'),
                        (array)$fields
                    );

                    $versionIndexes = array_merge(
                        Config::inst()->get('Versioned', 'indexes_for_versions_table'),
                        (array)$indexes
                    );
                } else {
                    // Create fields for any tables of subclasses
                    $versionFields = array_merge(
                        array(
                            "RecordID" => "Int",
                            "Version" => "Int",
                        ),
                        (array)$fields
                    );

                    //Unique indexes will not work on versioned tables, so we'll convert them to standard indexes:
                    if($indexes && count($indexes)) $indexes = $this->uniqueToIndex($indexes);
                    	
                    $versionIndexes = array_merge(
                        array(
                            'RecordID_Version' => array('type' => 'unique', 'value' => '"RecordID","Version"'),
                            'RecordID' => true,
                            'Version' => true,
                        ),
                        (array)$indexes
                    );
                }

                if(DB::get_schema()->hasTable("{$table}_versions")) {
                    // Fix data that lacks the uniqueness constraint (since this was added later and
                    // bugs meant that the constraint was validated)
                    $duplications = DB::query("SELECT MIN(\"ID\") AS \"ID\", \"RecordID\", \"Version\"
                        FROM \"{$table}_versions\" GROUP BY \"RecordID\", \"Version\"
                        HAVING COUNT(*) > 1");

                    foreach($duplications as $dup) {
                        DB::alteration_message("Removing {$table}_versions duplicate data for "
                        ."{$dup['RecordID']}/{$dup['Version']}" ,"deleted");
                        DB::prepared_query(
                            "DELETE FROM \"{$table}_versions\" WHERE \"RecordID\" = ?
                            AND \"Version\" = ? AND \"ID\" != ?",
                            array($dup['RecordID'], $dup['Version'], $dup['ID'])
                        );
                    }

                    // Remove junk which has no data in parent classes. Only needs to run the following
                    // when versioned data is spread over multiple tables
                    if(!$isRootClass && ($versionedTables = ClassInfo::dataClassesFor($table))) {

                        foreach($versionedTables as $child) {
                            if($table === $child) break; // only need subclasses
                            	
                            // Select all orphaned version records
                            $orphanedQuery = SQLSelect::create()
                            ->selectField("\"{$table}_versions\".\"ID\"")
                            ->setFrom("\"{$table}_versions\"");

                            // If we have a parent table limit orphaned records
                            // to only those that exist in this
                            if(DB::get_schema()->hasTable("{$child}_versions")) {
                                $orphanedQuery
                                ->addLeftJoin(
                                    "{$child}_versions",
                                    "\"{$child}_versions\".\"RecordID\" = \"{$table}_versions\".\"RecordID\"
                                    AND \"{$child}_versions\".\"Version\" = \"{$table}_versions\".\"Version\""
                                )
                                ->addWhere("\"{$child}_versions\".\"ID\" IS NULL");
                            }

                            $count = $orphanedQuery->count();
                            if($count > 0) {
                                DB::alteration_message("Removing {$count} orphaned versioned records", "deleted");
                                $ids = $orphanedQuery->execute()->column();
                                foreach($ids as $id) {
                                    DB::prepared_query(
                                        "DELETE FROM \"{$table}_versions\" WHERE \"ID\" = ?",
                                        array($id)
                                    );
                                }
                            }
                        }
                    }
                }

                DB::require_table("{$table}_versions_{$suffix}", $versionFields, $versionIndexes, true, $options);
            } else {
                DB::dont_require_table("{$table}_versions_{$suffix}");
                foreach($this->stages as $stage) {
                    if($stage != $this->defaultStage) DB::dont_require_table("{$table}_{$stage}_{$suffix}");
                }
            }
        }
    }

    /**
     * Helper for augmentDatabase() to find unique indexes and convert them to non-unique
     *
     * @param array $indexes The indexes to convert
     * @return array $indexes
     */
    private function uniqueToIndex($indexes) {
        $unique_regex = '/unique/i';
        $results = array();
        foreach ($indexes as $key => $index) {
            $results[$key] = $index;

            // support string descriptors
            if (is_string($index)) {
                if (preg_match($unique_regex, $index)) {
                    $results[$key] = preg_replace($unique_regex, 'index', $index);
                }
            }

            // canonical, array-based descriptors
            elseif (is_array($index)) {
                if (strtolower($index['type']) == 'unique') {
                    $results[$key]['type'] = 'index';
                }
            }
        }
        return $results;
    }

}
