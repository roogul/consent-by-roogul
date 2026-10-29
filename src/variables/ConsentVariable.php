<?php

namespace roogul\consent\variables;

use craft\helpers\Template;
use roogul\consent\Plugin;
use Twig\Markup;

/**
 * craft.consent
 */
class ConsentVariable
{
    /** Consent Mode defaults — place in <head> before GTM / gtag.js. */
    public function head(): Markup
    {
        return Template::raw(Plugin::getInstance()->renderHead());
    }

    /** Banner + preferences dialog (only needed when auto-inject is off). */
    public function banner(): Markup
    {
        return Template::raw(Plugin::getInstance()->renderBanner());
    }

    /** Cookie policy table, grouped by category. */
    public function table(): Markup
    {
        return Template::raw(Plugin::getInstance()->renderTable());
    }

    public function getCategories(): array
    {
        return Plugin::getInstance()->getSettings()->categories;
    }

    public function getCookies(): array
    {
        return Plugin::getInstance()->tableCookies();
    }
}
