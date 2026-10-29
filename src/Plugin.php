<?php

namespace roogul\consent;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\events\TemplateEvent;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use roogul\consent\models\Settings;
use roogul\consent\variables\ConsentVariable;
use roogul\consent\web\assets\consent\ConsentAsset;
use yii\base\Event;

/**
 * Consent by ROOGUL — lightweight cookie consent for Craft CMS.
 *
 * Everything decision-related happens client-side, so the plugin is safe
 * behind static/edge caches (Blitz, Craft Cloud). PHP only renders config
 * and markup that is identical for every visitor.
 *
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 */
class Plugin extends BasePlugin
{
    public string $schemaVersion = '1.0.0';
    public bool $hasCpSettings = true;

    private bool $bannerRendered = false;

    public function init(): void
    {
        parent::init();

        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            static function(Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('consent', ConsentVariable::class);
            }
        );

        Craft::$app->onInit(function() {
            $request = Craft::$app->getRequest();

            if ($request->getIsConsoleRequest() || !$request->getIsSiteRequest()) {
                return;
            }

            $this->attachSiteHooks();
        });
    }

    // Frontend
    // -------------------------------------------------------------------------

    /**
     * Inline <head> snippet that sets Google Consent Mode v2 defaults and
     * immediately re-applies a returning visitor's stored choice.
     * Must be output before GTM / gtag.js.
     */
    public function renderHead(): string
    {
        $settings = $this->getSettings();

        if (!$settings->enabled || !$settings->consentMode) {
            return '';
        }

        $config = Json::htmlEncode([
            'cookieName' => $settings->cookieName,
            'version' => $settings->version,
            'categories' => $this->frontendCategories(),
        ]);

        $js = <<<JS
(function(c){window.dataLayer=window.dataLayer||[];window.gtag=window.gtag||function(){dataLayer.push(arguments);};
var d={security_storage:'granted'};c.categories.forEach(function(k){k.gtag.forEach(function(g){d[g]=k.required?'granted':'denied';});});
d.wait_for_update=500;gtag('consent','default',d);
try{var m=document.cookie.match(new RegExp('(?:^|; )'+c.cookieName+'=([^;]*)'));if(!m)return;var s=JSON.parse(decodeURIComponent(m[1]));if(s.v!==c.version)return;
var u={};c.categories.forEach(function(k){if(!k.required&&s.c.indexOf(k.handle)>-1){k.gtag.forEach(function(g){u[g]='granted';});}});
if(Object.keys(u).length){gtag('consent','update',u);}}catch(e){}})({$config});
JS;

        return Html::script($js);
    }

    /**
     * Banner + preferences dialog markup, with the asset bundle registered.
     */
    public function renderBanner(): string
    {
        $settings = $this->getSettings();

        if (!$settings->enabled || $this->bannerRendered) {
            return '';
        }

        $this->bannerRendered = true;
        $view = Craft::$app->getView();
        $view->registerAssetBundle(ConsentAsset::class);

        return $view->renderTemplate('consent-by-roogul/_banner', [
            'settings' => $settings,
            'categories' => $settings->categories,
            'configJson' => Json::htmlEncode($this->frontendConfig()),
            'themeCss' => $settings->themeCss(),
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * Cookie policy table, grouped by category.
     */
    public function renderTable(): string
    {
        $settings = $this->getSettings();

        return Craft::$app->getView()->renderTemplate('consent-by-roogul/_table', [
            'categories' => $settings->categories,
            'cookies' => $this->tableCookies(),
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * Declared cookies plus the consent cookie itself, so the policy table
     * is always complete.
     */
    public function tableCookies(): array
    {
        $settings = $this->getSettings();
        $required = array_values(array_filter($settings->categories, static fn($c) => $c['required']));

        if (!$required) {
            return $settings->cookies;
        }

        $own = [
            'name' => $settings->cookieName,
            'provider' => Craft::$app->getSites()->getCurrentSite()->getName(),
            'category' => $required[0]['handle'],
            'purpose' => Craft::t('consent-by-roogul', 'Stores your cookie preferences.'),
            'duration' => Craft::t('consent-by-roogul', '{num} days', ['num' => $settings->cookieDays]),
            'party' => 'first',
        ];

        return array_merge([$own], $settings->cookies);
    }

    // Settings
    // -------------------------------------------------------------------------

    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * Full-page settings with tabs, instead of the single-column default.
     */
    public function getSettingsResponse(): mixed
    {
        /** @var \craft\web\Controller $controller */
        $controller = Craft::$app->controller;

        return $controller->renderTemplate('consent-by-roogul/settings', [
            'plugin' => $this,
            'settings' => $this->getSettings(),
        ]);
    }

    // Private
    // -------------------------------------------------------------------------

    private function attachSiteHooks(): void
    {
        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_PAGE_TEMPLATE,
            function(TemplateEvent $event) {
                $view = Craft::$app->getView();
                $settings = $this->getSettings();

                if (
                    $view->getTemplateMode() !== View::TEMPLATE_MODE_SITE ||
                    !$settings->enabled ||
                    !$settings->autoInjectBanner
                ) {
                    return;
                }

                $view->registerHtml($this->renderBanner(), View::POS_END, 'consent-banner');
            }
        );
    }

    private function frontendConfig(): array
    {
        $settings = $this->getSettings();

        return [
            'version' => $settings->version,
            'cookieName' => $settings->cookieName,
            'cookieDays' => $settings->cookieDays,
            'consentMode' => $settings->consentMode,
            'dev' => Craft::$app->getConfig()->getGeneral()->devMode,
            'categories' => $this->frontendCategories(),
            'cookies' => array_map(static fn($c) => [
                'name' => $c['name'],
                'category' => $c['category'],
            ], $settings->cookies),
        ];
    }

    private function frontendCategories(): array
    {
        return array_map(static fn($c) => [
            'handle' => $c['handle'],
            'required' => (bool)$c['required'],
            'gtag' => array_values(array_filter(array_map('trim', explode(',', (string)$c['gtag'])))),
        ], $this->getSettings()->categories);
    }
}
