<?php

namespace roogul\consent\models;

use craft\base\Model;

/**
 * Plugin settings. Stored in project config, and overridable per
 * environment with config/consent-by-roogul.php.
 */
class Settings extends Model
{
    public bool $enabled = true;

    /** Inject the banner before </body> automatically. Turn off to place {{ craft.consent.banner() }} yourself. */
    public bool $autoInjectBanner = true;

    /** Emit Google Consent Mode v2 signals. */
    public bool $consentMode = true;

    /** Bump to re-prompt every visitor (e.g. after adding a cookie to an optional category). */
    public int $version = 1;

    public string $cookieName = 'consent';
    public int $cookieDays = 180;

    // Copy (passed through |t('site'), so translate via translations/<lang>/site.php)
    public string $bannerTitle = 'We use cookies';
    public string $bannerText = 'We use necessary cookies to make this site work. With your permission, we’d also like to set optional cookies to understand how the site is used and to improve it.';
    public string $policyUrl = '';
    public string $policyLinkLabel = 'Cookie policy';
    public string $acceptLabel = 'Accept all';
    public string $rejectLabel = 'Reject all';
    public string $manageLabel = 'Manage preferences';
    public string $saveLabel = 'Save preferences';
    public string $preferencesTitle = 'Cookie preferences';

    // Theme — blank means "use the stylesheet default"
    public string $bgColor = '';
    public string $textColor = '';
    public string $mutedColor = '';
    public string $borderColor = '';
    public string $buttonColor = '';
    public string $buttonTextColor = '';
    public string $radius = '';

    /** Follow the visitor's dark mode for any colour left blank. */
    public bool $darkMode = true;

    /** Theme attribute => CSS custom property. */
    public const THEME_VARS = [
        'bgColor' => '--consent-bg',
        'textColor' => '--consent-fg',
        'mutedColor' => '--consent-muted',
        'borderColor' => '--consent-border',
        'buttonColor' => '--consent-btn-bg',
        'buttonTextColor' => '--consent-btn-fg',
    ];

    /**
     * @var array<array{handle:string,label:string,description:string,gtag:string,required:bool}>
     */
    public array $categories = [
        [
            'handle' => 'necessary',
            'label' => 'Strictly necessary',
            'description' => 'Required for the site to work, such as security and remembering your cookie choices. These can’t be switched off.',
            'gtag' => '',
            'required' => true,
        ],
        [
            'handle' => 'preferences',
            'label' => 'Preferences',
            'description' => 'Remember choices you make, such as language or region.',
            'gtag' => 'functionality_storage,personalization_storage',
            'required' => false,
        ],
        [
            'handle' => 'analytics',
            'label' => 'Analytics',
            'description' => 'Help us understand how the site is used so we can improve it.',
            'gtag' => 'analytics_storage',
            'required' => false,
        ],
        [
            'handle' => 'marketing',
            'label' => 'Marketing',
            'description' => 'Used to measure and personalise advertising.',
            'gtag' => 'ad_storage,ad_user_data,ad_personalization',
            'required' => false,
        ],
    ];

    /**
     * @var array<array{name:string,provider:string,category:string,purpose:string,duration:string,party:string}>
     */
    public array $cookies = [];

    public function init(): void
    {
        parent::init();
        $this->normalize();
    }

    public function beforeValidate(): bool
    {
        $this->normalize();
        return parent::beforeValidate();
    }

    protected function defineRules(): array
    {
        return [
            [['version', 'cookieDays'], 'integer', 'min' => 1],
            // 13 months is the usual regulator ceiling for consent lifetime
            [['cookieDays'], 'integer', 'max' => 395],
            [['cookieName'], 'match', 'pattern' => '/^[A-Za-z0-9_-]+$/'],
            [['cookieName', 'bannerTitle', 'bannerText', 'acceptLabel', 'rejectLabel', 'manageLabel', 'saveLabel', 'preferencesTitle'], 'required'],
            [array_keys(self::THEME_VARS), 'match', 'pattern' => '/^#(?:[0-9a-f]{3}|[0-9a-f]{6}|[0-9a-f]{8})$/i', 'message' => 'Use a hex colour, e.g. #1a1a1a.'],
            [['radius'], 'integer', 'min' => 0, 'max' => 64],
            [['categories'], 'validateCategories'],
            [['cookies'], 'validateCookies'],
        ];
    }

    public function validateCategories(string $attribute): void
    {
        if (!$this->categories) {
            $this->addError($attribute, 'Add at least one category.');
            return;
        }

        $handles = array_column($this->categories, 'handle');

        if (count($handles) !== count(array_unique($handles))) {
            $this->addError($attribute, 'Category handles must be unique.');
        }

        foreach ($handles as $handle) {
            if (!preg_match('/^[a-z][a-z0-9_-]*$/', $handle)) {
                $this->addError($attribute, "“{$handle}” isn’t a valid handle (lowercase letters, numbers, - and _).");
            }
        }

        if (!array_filter($this->categories, static fn($c) => $c['required'])) {
            $this->addError($attribute, 'Mark at least one category as required (strictly necessary).');
        }
    }

    public function validateCookies(string $attribute): void
    {
        $handles = array_column($this->categories, 'handle');

        foreach ($this->cookies as $cookie) {
            if (!in_array($cookie['category'], $handles, true)) {
                $this->addError($attribute, "Cookie “{$cookie['name']}” uses an unknown category.");
            }
        }
    }

    /**
     * Inline CSS for the chosen theme. Values are validated hex/integers,
     * so they're safe to print raw.
     */
    public function themeCss(): string
    {
        $vars = [];

        foreach (self::THEME_VARS as $attr => $var) {
            if ($this->$attr !== '' && !$this->hasErrors($attr)) {
                $vars[] = "{$var}:{$this->$attr}";
            }
        }

        if ($this->radius !== '' && ctype_digit($this->radius)) {
            $vars[] = "--consent-radius:{$this->radius}px";
        }

        // #consent beats the .consent dark-mode block on specificity
        return $vars ? '#consent.consent{' . implode(';', $vars) . '}' : '';
    }

    private function normalize(): void
    {
        foreach (array_keys(self::THEME_VARS) as $attr) {
            $value = strtolower(trim($this->$attr));
            if ($value !== '' && $value[0] !== '#') {
                $value = '#' . $value;
            }
            $this->$attr = $value;
        }

        $this->radius = trim($this->radius);

        // Editable tables post rows keyed row0, row1… with lightswitch values as '1' / ''
        $this->categories = array_values(array_filter(array_map(static fn($r) => [
            'handle' => trim((string)($r['handle'] ?? '')),
            'label' => trim((string)($r['label'] ?? '')),
            'description' => trim((string)($r['description'] ?? '')),
            'gtag' => trim((string)($r['gtag'] ?? '')),
            'required' => (bool)($r['required'] ?? false),
        ], $this->categories), static fn($r) => $r['handle'] !== ''));

        $this->cookies = array_values(array_filter(array_map(static fn($r) => [
            'name' => trim((string)($r['name'] ?? '')),
            'provider' => trim((string)($r['provider'] ?? '')),
            'category' => trim((string)($r['category'] ?? '')),
            'purpose' => trim((string)($r['purpose'] ?? '')),
            'duration' => trim((string)($r['duration'] ?? '')),
            'party' => ($r['party'] ?? 'first') === 'third' ? 'third' : 'first',
        ], $this->cookies), static fn($r) => $r['name'] !== ''));
    }
}
