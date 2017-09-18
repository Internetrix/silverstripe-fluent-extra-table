<?php
/**
 * @package fluent-extra
 * @author Yuchen Liu <yuchen.liu@internetrix.com.au>
 */
class Fluent_Extension extends DataExtension
{
	public function updateRegenerateRoutes(&$routes){
		
		$controller = Fluent::config()->handling_controller;
		
		$ownerClass = $this->ownerBaseClass;
		
		// Explicit routes
		foreach ($ownerClass::locales() as $locale) {
			$url = $ownerClass::alias($locale);
			$routes[$url.'/$URLSegment!//$Action/$ID/$OtherID'] = array(
					'Controller' => $controller,
					$ownerClass::config()->query_param => $locale
			);
			$routes[$url] = array(
					'Controller' => 'CompatibleFluentRootURLController',
					$ownerClass::config()->query_param => $locale
			);
		}
		
		// Home page route
		$routes[''] = array(
				'Controller' => 'CompatibleFluentRootURLController',
		);
		
	}
	
	/**
	 * Given a field on an object and optionally a locale, compare its locale value against the default locale value to
	 * determine if the value is changed at the given locale.
	 *
	 * @param  DataObject  $object
	 * @param  FormField   $field
	 * @param  string|null $locale Optional: if not provided, will be gathered from the request
	 * @return boolean
	 */
	public static function isTableFieldModified(DataObject $object, FormField $field, $locale = null)
	{
	    if (is_null($locale)) {
	        $locale = Fluent::current_locale();
	    }
	
	    if ($locale === $defaultLocale = Fluent::default_locale()) {
	        // It's the default locale, so it's never "modified" from the default locale value
	        return false;
	    }
	    
	    $defaultField = Fluent::db_field_for_locale($field->getName(), $defaultLocale);
	    $localeField  = $field->getName();
	
	    $defaultValue = $object->$defaultField;
	    $localeValue  = $object->$localeField;
	
	    if ((!empty($defaultValue) && empty($localeValue))
	        || ($defaultValue === $localeValue)
        ) {
            // Unchanged from default
            return false;
        }
	
        return true;
	}
}
