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
}
