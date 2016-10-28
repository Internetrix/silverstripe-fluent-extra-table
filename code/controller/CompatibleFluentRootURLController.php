<?php

/**
 * Home page controller for multiple locales
 *
 * @package fluent
 * @author Damian Mooyman <damian.mooyman@gmail.com>
 */
class CompatibleFluentRootURLController extends FluentRootURLController
{
    public function handleRequest(SS_HTTPRequest $request, DataModel $model = null)
    {
        self::$is_at_root = true;
        $this->setDataModel($model);

        $this->pushCurrent();
        $this->init();
        $this->setRequest($request);

        // Check for existing routing parameters, redirecting to another locale automatically if necessary
        $locale = Fluent::get_request_locale();
        if (empty($locale)) {

            // Determine if this user should be redirected
            $locale = $this->getRedirectLocale();
            $this->extend('updateRedirectLocale', $locale);

            // Check if the user should be redirected
            $domainDefault = Fluent::default_locale(true);
            if (Fluent::is_locale($locale) && ($locale !== $domainDefault)) {
                // Check new traffic with detected locale
                return $this->redirect(Fluent::locale_baseurl($locale));
            }

            // Reset parameters to act in the default locale
            $locale = $domainDefault;
            Fluent::set_persist_locale($locale);
            $params = $request->routeParams();
            $params[Fluent::config()->query_param] = $locale;
            $request->setRouteParams($params);
        }

        if (!DB::isActive() || !ClassInfo::hasTable('SiteTree')) {
            $this->response = new SS_HTTPResponse();
            $this->response->redirect(Director::absoluteBaseURL() . 'dev/build?returnURL=' . (isset($_GET['url']) ? urlencode($_GET['url']) : null));
            return $this->response;
        }

        $localeURL = Fluent::alias($locale);
        $request->setUrl(self::fluent_homepage_link($localeURL));
        $request->match($localeURL . '/$URLSegment//$Action', true);

        $controllerClass = Fluent::config()->handling_controller;
        $controller = new $controllerClass();
        $result = $controller->handleRequest($request, $model);

        $this->popCurrent();
        return $result;
    }
}
