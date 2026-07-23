# wp-plugin-kit — Gedeelde PHP runtime-bibliotheek

## Wat is dit?
Gedeelde PHP klassen voor alle Dr.Docks en Jellit WordPress plugins.
Dit is geen plugin — het is een Composer-pakket van type `library`.
Het bevat runtime-code die in productie meegeleverd wordt (via `require`, niet `require-dev`).

## Verschil met wp-coding-standards
| Pakket | Type | Composer sectie | In productie? | Inhoud |
|---|---|---|---|---|
| `drdocks/wp-coding-standards` | `phpcodesniffer-standard` | `require-dev` | Nee | PHPCS rules, PHPStan config, scaffold, tooling |
| `drdocks/wp-plugin-kit` | `library` | `require` | **Ja** | Updater, Settings base, Admin menu, Helpers |

## Distributie via Satis
Gedistribueerd via `https://drdocks.nl/satis` — dezelfde repository als wp-coding-standards.

Plugins gebruiken het als volgt:
```json
"require": {
    "drdocks/wp-plugin-kit": "^1.0"
},
"require-dev": {
    "drdocks/wp-coding-standards": "^2.1"
}
```

### Release workflow
1. Commit en push naar `main`
2. Tag: `git tag 1.x.x && git push --tags`
3. Satis herbouwen: `ssh drdocks "/opt/plesk/php/8.2/bin/php ~/satis-install/bin/satis build ~/satis/satis.json ~/httpdocs/satis"`
4. Plugins ontvangen de update bij `composer update`

---

## Klassen

### Kit_Updater
Plugin updater via drdocks.nl. Vervangt `Dr_Docks_Updater` uit de core plugin.

```php
$updater = new Kit_Updater( array(
    'plugin_file' => plugin_basename( __FILE__ ),
    'slug'        => 'my-plugin',
    'version'     => MY_PLUGIN_VERSION,
    'plugin_name' => 'My Plugin',
) );
$updater->init();
```

Hooks: `update_plugins_drdocks.nl`, `plugins_api`, `admin_enqueue_scripts`.
Cache: transient `kit_update_{slug}` (6 uur).

#### Rich "View details" modal (sinds 1.1.0)
Optioneel, volledig data-gedreven vanuit de update-manifest (JSON op drdocks.nl).
Levert de manifest een `sections`-map (`description`, `installation`, `faq`,
`screenshots`) dan bouwt `plugin_info()` de volledige tabbed modal; ontbreekt die,
dan blijft de historische sobere modal (naam + changelog) — dus **backwards
compatible** voor elke andere plugin.

- WordPress her-filtert elke sectie met zijn strikte plugin-modal kses-allowlist
  (géén inline styles, `<style>`, `<svg>` of `<table>`). Lever daarom **schone
  semantische HTML** (div/span + class) — de styling komt uit `assets/plugin-info.css`,
  die alleen in de plugin-information iframe voor déze plugin wordt ingeladen.
- Merkkleuren komen uit de manifest-`brand`-map en worden als inline `:root`
  custom properties geïnjecteerd (`build_brand_css()`), zodat deze library
  merk-neutraal blijft. Onbekende keys → neutrale defaults in de CSS.
- Extra optionele manifest-velden die de native sidebar/banner vullen:
  `tags`, `banners`, `active_installs`, `contributors`, `donate_link`, `added`,
  `requires`, `requires_php`, `tested`, `homepage`, `author`, `name`.
- De changelog-tab komt altijd uit het `changelog`-markdownveld (CHANGELOG.md via
  release.sh), nooit uit `sections`.

De consumerende plugin levert `update-manifest.json` in zijn repo aan; `release.sh`
(wp-coding-standards) merget die over de gegenereerde base-JSON bij een release.

### Kit_Settings
Abstract settings base class. Concrete klassen definiëren `OPTION_KEY` en `DEFAULTS`.

```php
class My_Settings extends Kit_Settings {
    const OPTION_KEY = 'my_plugin_settings';
    const DEFAULTS   = array(
        'enabled' => true,
        'limit'   => 10,
    );
}

My_Settings::get( 'enabled' );           // true
My_Settings::get( 'limit', 5 );          // 10 (stored) of 5 (fallback)
My_Settings::update( array( 'limit' => 20 ) );
My_Settings::set_defaults();             // Bij activatie
My_Settings::delete();                   // In uninstall.php
My_Settings::verify_ajax_admin( 'nonce_action' ); // In AJAX handlers
```

In-memory cache is per concrete class — meerdere plugins interfereren niet.
`set_defaults()` merged new defaults into existing values (safe voor updates).

### Kit_Admin_Menu
Gedeeld top-level admin menu ("Dr.Docks") waaronder plugins subpagina's registreren.

```php
// Priority 5: parent menu aanmaken (safe om meerdere keren aan te roepen).
add_action( 'admin_menu', array( Kit_Admin_Menu::class, 'register_parent' ), 5 );

// Priority 10+: eigen submenu registreren.
add_action( 'admin_menu', function () {
    Kit_Admin_Menu::add_submenu( 'My Plugin', 'my-plugin', array( My_Settings::class, 'render' ) );
} );
```

Klantspecifieke plugins (LPP, LD) die hun eigen top-level menu willen behouden,
hoeven Kit_Admin_Menu niet te gebruiken — het is optioneel.

### Kit_Helpers
Statische utility methoden.

```php
Kit_Helpers::is_rest();   // Is dit een REST API request?
Kit_Helpers::is_ajax();   // Is dit een AJAX request?
Kit_Helpers::is_async();  // Is dit REST of AJAX?
```

---

## Autoloading
Gebruikt `classmap` autoloading (consistent met WordPress `class-` prefix conventie).
Na `composer install` zijn alle `Kit_*` klassen automatisch beschikbaar.

---

## Claude-gedragsregels

### Claude vraagt om goedkeuring voor
- Wijzigingen aan bestaande class interfaces (breaking voor alle plugins)
- Toevoegen van nieuwe dependencies in composer.json
- Tags en Satis rebuilds

### Claude mag zonder te vragen
- Nieuwe klassen toevoegen (additive, niet breaking)
- Documentatie bijwerken
- Committen en pushen

### Impact
Wijzigingen aan dit pakket raken alle plugins die het als `require` gebruiken.
Test altijd tegen minimaal één plugin: `cd ../lighting-downloads && composer update drdocks/wp-plugin-kit && composer check`

---

## Dependencies
- **PHP:** >= 8.0
- **WordPress:** functies uit wp-includes (get_option, add_menu_page, etc.)
