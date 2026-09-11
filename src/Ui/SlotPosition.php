<?php

declare(strict_types=1);

namespace AdminBolt\Plugin\Ui;

/**
 * The catalogue of positions a slot may be drawn in.
 *
 * Every place the panel will put something for a plugin has a name here, and
 * the name is the contract. It is written out rather than derived from
 * whatever the panel renders with today, for the same reason hook names are
 * written out in {@see \AdminBolt\Plugin\Hook\Hook}: a plugin that declares
 * "footer" should keep drawing in the footer for as long as the panel has
 * one, whatever the panel is built on by then. The panel keeps the map from
 * these names to its own view layer, and that map is the only thing a change
 * of view layer touches.
 *
 * Names are added and never repurposed. A position that stops existing in the
 * panel is a position the panel has to find a home for, because plugins in
 * the wild already declare it.
 *
 * Three kinds of position are deliberately missing: the document head, the
 * script block and the stylesheet block. They take markup and nothing else,
 * and a plugin sends a description the panel renders rather than markup of
 * its own.
 */
final class SlotPosition
{
    // The frame around it
    public const BODY_END = 'body.end';
    public const BODY_START = 'body.start';
    public const FOOTER = 'footer';
    public const GLOBAL_SEARCH_AFTER = 'global-search.after';
    public const GLOBAL_SEARCH_BEFORE = 'global-search.before';
    public const GLOBAL_SEARCH_END = 'global-search.end';
    public const GLOBAL_SEARCH_START = 'global-search.start';
    public const SIDEBAR_FOOTER = 'sidebar.footer';
    public const SIDEBAR_NAV_END = 'sidebar.nav.end';
    public const SIDEBAR_NAV_START = 'sidebar.nav.start';
    public const TENANT_MENU_AFTER = 'tenant-menu.after';
    public const TENANT_MENU_BEFORE = 'tenant-menu.before';
    public const TOPBAR_AFTER = 'topbar.after';
    public const TOPBAR_BEFORE = 'topbar.before';
    public const TOPBAR_END = 'topbar.end';
    public const TOPBAR_START = 'topbar.start';
    public const USER_MENU_AFTER = 'user-menu.after';
    public const USER_MENU_BEFORE = 'user-menu.before';
    public const USER_MENU_PROFILE_AFTER = 'user-menu.profile.after';
    public const USER_MENU_PROFILE_BEFORE = 'user-menu.profile.before';

    // The page
    public const CONTENT_AFTER = 'content.after';
    public const CONTENT_BEFORE = 'content.before';
    public const CONTENT_END = 'content.end';
    public const CONTENT_START = 'content.start';
    public const PAGE_END = 'page.end';
    public const PAGE_FOOTER_WIDGETS_AFTER = 'page.footer-widgets.after';
    public const PAGE_FOOTER_WIDGETS_BEFORE = 'page.footer-widgets.before';
    public const PAGE_HEADER_WIDGETS_AFTER = 'page.header-widgets.after';
    public const PAGE_HEADER_WIDGETS_BEFORE = 'page.header-widgets.before';
    public const PAGE_HEADER_ACTIONS_AFTER = 'page.header.actions.after';
    public const PAGE_HEADER_ACTIONS_BEFORE = 'page.header.actions.before';
    public const PAGE_START = 'page.start';
    public const PAGE_SUB_NAVIGATION_END_AFTER = 'page.sub-navigation.end.after';
    public const PAGE_SUB_NAVIGATION_END_BEFORE = 'page.sub-navigation.end.before';
    public const PAGE_SUB_NAVIGATION_SELECT_AFTER = 'page.sub-navigation.select.after';
    public const PAGE_SUB_NAVIGATION_SELECT_BEFORE = 'page.sub-navigation.select.before';
    public const PAGE_SUB_NAVIGATION_SIDEBAR_AFTER = 'page.sub-navigation.sidebar.after';
    public const PAGE_SUB_NAVIGATION_SIDEBAR_BEFORE = 'page.sub-navigation.sidebar.before';
    public const PAGE_SUB_NAVIGATION_START_AFTER = 'page.sub-navigation.start.after';
    public const PAGE_SUB_NAVIGATION_START_BEFORE = 'page.sub-navigation.start.before';
    public const PAGE_SUB_NAVIGATION_TOP_AFTER = 'page.sub-navigation.top.after';
    public const PAGE_SUB_NAVIGATION_TOP_BEFORE = 'page.sub-navigation.top.before';
    public const SIMPLE_PAGE_END = 'simple-page.end';
    public const SIMPLE_PAGE_START = 'simple-page.start';

    // Resource pages
    public const RESOURCE_PAGES_LIST_RECORDS_TABLE_AFTER = 'resource.pages.list-records.table.after';
    public const RESOURCE_PAGES_LIST_RECORDS_TABLE_BEFORE = 'resource.pages.list-records.table.before';
    public const RESOURCE_PAGES_LIST_RECORDS_TABS_END = 'resource.pages.list-records.tabs.end';
    public const RESOURCE_PAGES_LIST_RECORDS_TABS_START = 'resource.pages.list-records.tabs.start';
    public const RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_AFTER = 'resource.pages.manage-related-records.table.after';
    public const RESOURCE_PAGES_MANAGE_RELATED_RECORDS_TABLE_BEFORE = 'resource.pages.manage-related-records.table.before';
    public const RESOURCE_RELATION_MANAGER_AFTER = 'resource.relation-manager.after';
    public const RESOURCE_RELATION_MANAGER_BEFORE = 'resource.relation-manager.before';
    public const RESOURCE_TABS_END = 'resource.tabs.end';
    public const RESOURCE_TABS_START = 'resource.tabs.start';

    // The login and password screens
    public const AUTH_LOGIN_FORM_AFTER = 'auth.login.form.after';
    public const AUTH_LOGIN_FORM_BEFORE = 'auth.login.form.before';
    public const AUTH_PASSWORD_RESET_REQUEST_FORM_AFTER = 'auth.password-reset.request.form.after';
    public const AUTH_PASSWORD_RESET_REQUEST_FORM_BEFORE = 'auth.password-reset.request.form.before';
    public const AUTH_PASSWORD_RESET_RESET_FORM_AFTER = 'auth.password-reset.reset.form.after';
    public const AUTH_PASSWORD_RESET_RESET_FORM_BEFORE = 'auth.password-reset.reset.form.before';
    public const AUTH_REGISTER_FORM_AFTER = 'auth.register.form.after';
    public const AUTH_REGISTER_FORM_BEFORE = 'auth.register.form.before';

    /**
     * @return list<string>
     */
    public static function all(): array
    {
        static $all = null;

        if ($all === null) {
            $all = array_values((new \ReflectionClass(self::class))->getConstants());
        }

        return $all;
    }

    public static function isKnown(string $position): bool
    {
        return in_array($position, self::all(), true);
    }

    /**
     * The name somebody probably meant, for a manifest with a typo in it.
     */
    public static function closest(string $position): ?string
    {
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach (self::all() as $candidate) {
            $distance = levenshtein($position, $candidate);

            if ($distance < $bestDistance) {
                $bestDistance = $distance;
                $best = $candidate;
            }
        }

        // Far enough away and a suggestion is noise rather than help.
        return $bestDistance <= max(3, (int) (strlen($position) / 3)) ? $best : null;
    }
}
