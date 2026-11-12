<?php
namespace MassINC;

defined('ABSPATH') || exit;

/**
 * The names staff pick from in "Select your name" on the candidate page.
 *
 * Staff share one sign-in, so reviewers and note authors identify themselves
 * by name. The list starts empty and grows as staff add their own names.
 * It is stored as a single option because it is short and only ever appended to.
 */
class StaffNames {
    const OPTION     = 'minc_hiring_staff_names';
    const MAX_NAMES  = 200;
    const MAX_LENGTH = 100;

    /**
     * @return string[] Names in alphabetical order.
     */
    public static function all() {
        $names = get_option(self::OPTION, []);
        $names = is_array($names) ? array_values(array_filter($names, 'is_string')) : [];
        natcasesort($names);
        return array_values($names);
    }

    /**
     * Clean a typed name: no markup, one space between words, trimmed.
     */
    public static function normalize($name) {
        return trim(sanitize_text_field((string) $name));
    }

    /**
     * The name as spelled on the staff list if it is listed (ignoring case),
     * otherwise the cleaned name. Keeps ratings and notes under one spelling.
     */
    public static function canonical($name) {
        $name = self::normalize($name);
        foreach (self::all() as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($name)) {
                return $existing;
            }
        }
        return $name;
    }

    /**
     * Add a name unless someone with the same name (ignoring case) is already
     * listed, in which case the existing spelling is returned.
     *
     * @param string $name
     * @return array|\WP_Error ['name' => string, 'created' => bool]
     */
    public static function add($name) {
        $name = trim(sanitize_text_field((string) $name));

        if ($name === '') {
            return new \WP_Error('massinc_name_required', 'Type your name first.', ['status' => 400]);
        }
        if (mb_strlen($name) > self::MAX_LENGTH) {
            return new \WP_Error(
                'massinc_name_too_long',
                sprintf('Names can be up to %d characters.', self::MAX_LENGTH),
                ['status' => 400]
            );
        }

        $names = self::all();
        foreach ($names as $existing) {
            if (mb_strtolower($existing) === mb_strtolower($name)) {
                return ['name' => $existing, 'created' => false];
            }
        }

        if (count($names) >= self::MAX_NAMES) {
            return new \WP_Error('massinc_too_many_names', 'The staff list is full.', ['status' => 400]);
        }

        $names[] = $name;
        update_option(self::OPTION, $names, false);

        return ['name' => $name, 'created' => true];
    }

    /**
     * Take a name off the list (matching ignores case). This only edits the
     * list: ratings and notes record the reviewer's name as text of their own,
     * so anything already written under the name stays.
     *
     * @param string $name
     * @return array ['name' => string, 'removed' => bool]
     */
    public static function remove($name) {
        $name = trim(sanitize_text_field((string) $name));
        $kept = [];
        $removed = false;

        foreach (self::all() as $existing) {
            if ($name !== '' && mb_strtolower($existing) === mb_strtolower($name)) {
                $removed = true;
                continue;
            }
            $kept[] = $existing;
        }

        if ($removed) {
            update_option(self::OPTION, $kept, false);
        }

        return ['name' => $name, 'removed' => $removed];
    }
}
