<?php
/**
 * All functions are copied from fluent module > FluentSiteTree.php
 * 
 * @package fluent-extra
 * @author Jason Zhang <jason.zhang@internetrix.com.au>
 */
class ExtraTable_FluentSiteTree extends ExtraTable_FluentExtension
{
	/**
	 * @var SiteTree
	 */
	protected $owner;
	
    public function MetaTags(&$tags)
    {
    	if(Fluent::config()->perlang_persite){
    		$tags .= $this->owner->renderWith('FluentSiteTree_MetaTags');
    	}
    }

    public function updateRelativeLink(&$base, &$action)
    {

    	if(Director::is_absolute_url($base)) return;
    	
    	if($base == 'home') {$base = '/';}
    	
        // Don't inject locale to subpages
        if ( ($this->owner->ParentID && SiteTree::config()->nested_urls) && 
        		!(class_exists('Site') && in_array($this->owner->ParentID, Site::get()->getIDList())) 	// add compatibility with Multisites
        	) {
            return;
        }

        // For blank/temp pages such as Security controller fallback to querystring
        $locale = Fluent::current_locale();
        if (!$this->owner->exists()) {
            $base = Controller::join_links($base, '?'.Fluent::config()->query_param.'='.urlencode($locale));
            return;
        }

        // Check if this locale is the default for its own domain
        $domain = Fluent::domain_for_locale($locale);
        if ($locale === Fluent::default_locale($domain)) {
            // For home page in the default locale, do not alter home url
            if ($base === null) {
                return;
            }
            
            // If default locale shouldn't have prefix, then don't add prefix
            if (Fluent::disable_default_prefix()) {
                return;
            }

            // For all pages on a domain where there is only a single locale,
            // then the domain itself is sufficient to distinguish that domain
            // See https://github.com/tractorcow/silverstripe-fluent/issues/75
            $domainLocales = Fluent::locales($domain);
            if (count($domainLocales) === 1) {
                return;
            }
        }

        // Simply join locale root with base relative URL
        $localeURL = Fluent::alias($locale);
        $base = Controller::join_links($localeURL, $base);
    }

}
