<?php
/**
 * Minimal Polylang stand-ins for the test suite. Loaded by bootstrap.php
 * only when Polylang itself is absent.
 *
 * State: post meta `_fake_pll_lang` (language slug) and the option
 * `_fake_pll_tr` (list of translation groups, each a lang => post ID map).
 */

if ( ! function_exists( 'pll_languages_list' ) ) {
	function pll_languages_list( $args = [] ) {
		return [ 'en', 'es' ];
	}
}

if ( ! function_exists( 'pll_set_post_language' ) ) {
	function pll_set_post_language( $post_id, $lang ) {
		update_post_meta( (int) $post_id, '_fake_pll_lang', (string) $lang );
	}
}

if ( ! function_exists( 'pll_get_post_language' ) ) {
	function pll_get_post_language( $post_id, $field = 'slug' ) {
		$lang = get_post_meta( (int) $post_id, '_fake_pll_lang', true );
		return '' === $lang ? false : $lang;
	}
}

if ( ! function_exists( 'pll_save_post_translations' ) ) {
	function pll_save_post_translations( $translations ) {
		$translations = array_map( 'intval', (array) $translations );
		$groups       = [];
		foreach ( (array) get_option( '_fake_pll_tr', [] ) as $group ) {
			$group = array_diff( $group, $translations );
			if ( count( $group ) > 0 ) {
				$groups[] = $group;
			}
		}
		$groups[] = $translations;
		update_option( '_fake_pll_tr', $groups );
	}
}

if ( ! function_exists( 'pll_get_post_translations' ) ) {
	function pll_get_post_translations( $post_id ) {
		foreach ( (array) get_option( '_fake_pll_tr', [] ) as $group ) {
			if ( in_array( (int) $post_id, $group, true ) ) {
				return $group;
			}
		}
		$lang = pll_get_post_language( $post_id );
		return $lang ? [ $lang => (int) $post_id ] : [];
	}
}
