# Locale

The `Locale` component provides a backed enum containing 700+ locale identifiers (such as `en_US`, `fr_FR`, `ja_JP`). Using an enum instead of raw strings eliminates typos, enables IDE autocompletion, and makes locale parameters type-safe throughout your application.

## Usage

### Selecting a Locale

Pick a locale by name -- every case maps to its standard locale string:

```php
use Psl\IO;
use Psl\Locale\Locale;

$locale = Locale::EnglishUnitedStates; // 'en_US'
IO\write_line('en_US: %s', $locale->value);

$locale = Locale::FrenchFrance; // 'fr_FR'
IO\write_line('fr_FR: %s', $locale->value);

$locale = Locale::Japanese; // 'ja'
IO\write_line('ja: %s', $locale->value);

$locale = Locale::ChineseSimplified; // 'zh_Hans'
IO\write_line('zh_Hans: %s', $locale->value);
```

### Default Locale

`Locale::default()` reads the locale configured via PHP's `intl.default_locale` setting. If no locale is configured or the configured value is not recognized, it falls back to `Locale::English`:

```php
use Psl\IO;
use Psl\Locale\Locale;

$locale = Locale::default();
IO\write_line('Default locale: %s', $locale->value);
```

### Inspecting a Locale

Each locale instance exposes methods to extract its parts:

```php
use Psl\IO;
use Psl\Locale\Locale;

$locale = Locale::FrenchCanada;

IO\write_line('Value: %s', $locale->value); // 'fr_CA'
IO\write_line('Language: %s', $locale->getLanguage()); // 'fr'
IO\write_line('Region: %s', $locale->getRegion() ?? '(none)'); // 'CA'
IO\write_line('Display name: %s', $locale->getDisplayName()); // 'French (Canada)'
IO\write_line('Display language: %s', $locale->getDisplayLanguage()); // 'French'
IO\write_line('Display region: %s', $locale->getDisplayRegion() ?? '(none)'); // 'Canada'
IO\write_line('Has region: %s', $locale->hasRegion() ? 'yes' : 'no'); // true
IO\write_line('Has script: %s', $locale->hasScript() ? 'yes' : 'no'); // false
```

Script-based locales also work:

```php
use Psl\IO;
use Psl\Locale\Locale;

$locale = Locale::SerbianLatin;

IO\write_line('Script: %s', $locale->getScript() ?? '(none)'); // 'Latn'
IO\write_line('Has script: %s', $locale->hasScript() ? 'yes' : 'no'); // true
```

### Integration with DateTime

The `Locale` enum is used by PSL's `DateTime` formatting to produce locale-aware output:

```php
use Psl\DateTime;
use Psl\IO;
use Psl\Locale\Locale;

$now = DateTime\DateTime::now();
$formatted = $now->format('EEEE, MMMM d', locale: Locale::GermanGermany);
IO\write_line('German date: %s', $formatted);
```

See [src/Psl/Locale/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/locale/src/Psl/Locale/) for the full API.
