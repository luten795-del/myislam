<?php
/**
 * Color-classification helpers, shared between content.php (server-rendered
 * lesson pages) and api/get_lesson_content.php (the JSON feed used to
 * download lessons for offline reading).
 *
 * WHY THIS IS ITS OWN FILE
 * The offline API needs to make the exact same "is this text color really
 * admin-chosen, or just the untouched default?" decision that content.php
 * makes when rendering live - otherwise a lesson could look correct online
 * and wrong once downloaded (or vice versa). Keeping this logic in one file
 * that both include means there's only one place to fix if the rule ever
 * needs to change.
 */

/**
 * The database is returning text_color = #1f2937 and background_color =
 * #ffffff for essentially every section, including ones the admin never
 * touched the color picker for - these are this theme's own light-mode
 * default --text/--surface values, saved as literal column values instead
 * of being left null. There's no way to tell "admin deliberately picked
 * this exact pair" apart from "this is just the untouched default", so
 * this treats that specific pair as NOT a real customization, letting dark
 * mode (or the offline viewer) override it like any other uncolored
 * section. Any other color an admin actually picks is unaffected and still
 * honored as authored.
 */
function isUntouchedDefaultColorPair(?string $textColor, ?string $bgColor): bool
{
    $normalize = static fn(?string $c) => $c ? strtolower(trim($c)) : '';
    return $normalize($textColor) === '#1f2937' && $normalize($bgColor) === '#ffffff';
}

/**
 * Whether this section/span has a text color that should be honored as
 * genuinely admin-chosen in both themes. False for no saved color at all,
 * and false for the untouched-default pair above (see its docblock).
 */
function hasCustomTextColor(?string $textColor, ?string $bgColor): bool
{
    if (!$textColor || $textColor === '') {
        return false;
    }
    if (isUntouchedDefaultColorPair($textColor, $bgColor)) {
        return false;
    }
    return true;
}

/**
 * A saved text color is only "self-contained" (safe to show as-authored in
 * both themes) when it was chosen alongside its own background color - the
 * pair travels together, so contrast is preserved no matter which theme the
 * reader is in. A dark text color saved WITHOUT a background sits on the
 * theme's own surface instead, which is light in light mode but dark in
 * dark mode - so a near-black color that was legible on the light surface
 * becomes invisible on the dark one. This flags exactly that case, so the
 * caller can fall back to the theme's readable text color in dark mode only,
 * while leaving every other saved color (colorful, light, or paired with a
 * background) untouched.
 */
function needsDarkModeTextFallback(?string $textColor, ?string $bgColor): bool
{
    if (!hasCustomTextColor($textColor, $bgColor)) {
        // Not a real customization - the .no-custom-text-color CSS path
        // already forces var(--text) on this element, so no extra flag needed.
        return false;
    }
    if ($bgColor && $bgColor !== '') {
        // Background travels with the text - contrast is self-contained.
        return false;
    }
    return isColorDark($textColor);
}

function isColorDark(string $color): bool
{
    $color = strtolower(trim($color));
    if ($color === '') {
        return false;
    }
    if ($color === 'black') {
        return true;
    }
    if ($color === 'white') {
        return false;
    }
    if ($color[0] !== '#') {
        // rgb()/hsl()/named colors we don't parse - leave the saved color alone.
        return false;
    }
    $hex = ltrim($color, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) {
        return false;
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $luminance = (0.299 * $r + 0.587 * $g + 0.114 * $b) / 255;
    return $luminance < 0.35;
}
