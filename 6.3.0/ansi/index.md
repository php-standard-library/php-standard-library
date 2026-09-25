# Ansi

The `Ansi` component provides pure functions for constructing ANSI escape sequences -- text styling, colors, cursor movement, screen manipulation, hyperlinks, and terminal mode control.

All functions return immutable sequence objects that implement `CommandInterface`. No I/O is performed -- call `toString()` to get the raw escape string for writing to a terminal.

## Usage

```php
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Ansi\Cursor;
use Psl\Ansi\Screen;
use Psl\Ansi\Style;
use Psl\IO;

// Style and color text
IO\write(Ansi\apply('Hello, world!', Style\bold(), Ansi\foreground(Color\green())));
IO\write("\n");

// Cursor movement
IO\write(Cursor\move_to(1, 1)->toString());
IO\write(Cursor\hide()->toString());

// Screen control
IO\write(Screen\erase(Screen\EraseMode::Full)->toString());
IO\write(Screen\title('My App')->toString());

// Hyperlinks (OSC 8)
IO\write(Ansi\link('Click here', 'https://example.com', Style\underline()));
IO\write("\n");

// Strip ANSI sequences from text
$plain = Ansi\strip("\e[1mBold\e[0m"); // "Bold"
IO\write_line('Stripped: %s', $plain);
```

## Design

The component is organized around three sequence types that implement `CommandInterface`:

- **`ControlSequenceIntroducer`** (CSI) -- sequences starting with `\e[`, used for cursor movement, text styling (SGR), screen erasing, scrolling, and DEC private mode toggling.
- **`OperatingSystemCommand`** (OSC) -- sequences starting with `\e]`, used for setting window titles, hyperlinks, clipboard access, and desktop notifications.
- **`ControlCharacter`** -- single-byte control characters (e.g. BEL `\x07`) that don't fit the CSI or OSC format.

All are `final readonly` classes with a `toString()` method that produces the raw escape string. Functions in the component return one of these types (or a plain `string` for composed output like `apply()` and `link()`).

## Text Styling

The `apply()` function wraps text with SGR (Select Graphic Rendition) sequences and an automatic reset. It accepts any combination of styles and colors:

```php
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Ansi\Style;
use Psl\IO;

// Combine multiple styles
IO\write(Ansi\apply('important', Style\bold(), Style\underline(), Ansi\foreground(Color\red())));
IO\write("\n");

// Background colors
IO\write(Ansi\apply(' PASS ', Style\bold(), Ansi\foreground(Color\white()), Ansi\background(Color\green())));
IO\write("\n");
```

Colors are created through factory functions in the `Color` namespace and passed to `foreground()` or `background()`:

- **16 named colors** -- `black`, `red`, `green`, `yellow`, `blue`, `magenta`, `cyan`, `white`, and their `bright_*` variants
- **ANSI-256** -- `Color\ansi256(int $code)` for the 256-color palette
- **24-bit RGB** -- `Color\rgb(int $r, int $g, int $b)` for true color
- **Hex** -- `Color\hex(string $hex)` for hex color strings like `'#FF6600'`

## Hyperlinks

The `link()` function wraps text in an OSC 8 hyperlink, with optional SGR styles applied to the visible text:

```php
use Psl\Ansi;
use Psl\Ansi\Style;
use Psl\IO;

IO\write(Ansi\link('GitHub', 'https://github.com', Style\bold(), Style\underline()));
IO\write("\n");
```

Terminal emulators that support OSC 8 render the text as a clickable link.

## Cursor Control

Functions in the `Cursor` namespace manipulate cursor position and visibility:

```php
use Psl\Ansi\Cursor;
use Psl\IO;

IO\write(Cursor\move_to(1, 1)->toString()); // absolute position (1-based row, column)
IO\write(Cursor\up(5)->toString()); // relative movement
IO\write(Cursor\down(3)->toString());
IO\write(Cursor\forward(10)->toString());
IO\write(Cursor\back(2)->toString());
IO\write(Cursor\save()->toString()); // save position
IO\write(Cursor\restore()->toString()); // restore saved position
IO\write(Cursor\hide()->toString()); // hide cursor
IO\write(Cursor\show()->toString()); // show cursor
```

## Screen Control

Functions in the `Screen` namespace control the display, window properties, and terminal modes.

```php
use Psl\Ansi\Screen;
use Psl\IO;

// Erasing and scrolling
IO\write(Screen\erase(Screen\EraseMode::Full)->toString()); // erase entire screen
IO\write(Screen\erase_line(Screen\LineEraseMode::Right)->toString()); // erase from cursor to end of line
IO\write(Screen\scroll_up(5)->toString()); // scroll viewport up

// Window properties
IO\write(Screen\title('My App')->toString()); // set window title
IO\write(Screen\notify('Build complete')->toString()); // desktop notification

// Progress indicator (supported by Windows Terminal, ConEmu, Kitty, Ghostty)
IO\write(Screen\progress(Screen\ProgressState::Normal, 50)->toString()); // 50% progress
IO\write(Screen\progress(Screen\ProgressState::Indeterminate)->toString()); // animated spinner
IO\write(Screen\progress_clear()->toString()); // remove indicator

// Terminal modes
IO\write(Screen\set_mode(Screen\ScreenMode::AlternateScreen)->toString()); // alternate screen buffer for TUIs
IO\write(Screen\set_mode(Screen\ScreenMode::MouseTracking)->toString()); // mouse click and scroll events
IO\write(Screen\set_mode(Screen\ScreenMode::BracketedPaste)->toString()); // distinguish pasted text from typed input
```

## Examples

### Styled Log Output

```php
use Psl\Ansi;
use Psl\Ansi\Color;
use Psl\Ansi\Style;
use Psl\IO;

IO\write_line(Ansi\apply('Error: something went wrong', Style\bold(), Ansi\foreground(Color\red())));
IO\write_line(Ansi\apply('Warning: check configuration', Ansi\foreground(Color\yellow())));
IO\write_line(Ansi\apply('Success!', Style\bold(), Ansi\foreground(Color\green())));
```

### TUI Setup and Teardown

```php
use Psl\Ansi;
use Psl\Ansi\Cursor;
use Psl\Ansi\Screen;
use Psl\IO;

// Enter TUI mode
IO\write(
    Screen\set_mode(Screen\ScreenMode::AlternateScreen)->toString()
        . Cursor\hide()->toString()
        . Screen\erase(Screen\EraseMode::Full)->toString()
        . Cursor\move_to(1, 1)->toString()
        . Screen\title('My TUI App')->toString(),
);

// ... render UI ...

// Exit TUI mode
IO\write(
    Cursor\show()->toString()
        . Ansi\reset()->toString()
        . Screen\reset_mode(Screen\ScreenMode::AlternateScreen)->toString()
        . Screen\title('')->toString(),
);
```

See [src/Psl/Ansi/](https://github.com/php-standard-library/php-standard-library/tree/6.3.0/packages/ansi/src/Psl/Ansi/) for the full API.
