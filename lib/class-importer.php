<?php

namespace WP_Parser;

use WP_CLI;

/**
 * Handles creating and updating posts from (functions|classes|files) generated by phpDoc.
 */
class Importer {

	/**
	 * Taxonony name for files
	 *
	 * @var string
	 */
	public $taxonomy_file;

	/**
	 * Taxonomy name for an item's @since tag
	 *
	 * @var string
	 */
	public $taxonomy_since_version;

	/**
	 * Taxonomy name for an item's @package/@subpackage tags
	 *
	 * @var string
	 */
	public $taxonomy_package;

	/**
	 * Post type name for functions
	 *
	 * @var string
	 */
	public $post_type_function;

	/**
	 * Post type name for classes
	 *
	 * @var string
	 */
	public $post_type_class;

	/**
	 * Post type name for methods
	 *
	 * @var string
	 */
	public $post_type_method;

	/**
	 * Post type name for hooks
	 *
	 * @var string
	 */
	public $post_type_hook;

	/**
	 * Handy store for meta about the current item being imported
	 *
	 * @var array
	 */
	public $file_meta = array();

	/**
	 * @var array Human-readable errors
	 */
	public $errors = array();

	/**
	 * @var array Cached items of inserted terms
	 */
	protected $inserted_terms = array();

	/**
	 * Constructor. Sets up post type/taxonomy names.
	 *
	 * @param array $args Optional. Associative array; class property => value.
	 */
	public function __construct( array $args = array() ) {

		$r = wp_parse_args(
			$args,
			array(
				'post_type_class'        => 'wp-parser-class',
				'post_type_method'       => 'wp-parser-method',
				'post_type_function'     => 'wp-parser-function',
				'post_type_hook'         => 'wp-parser-hook',
				'taxonomy_file'          => 'wp-parser-source-file',
				'taxonomy_package'       => 'wp-parser-package',
				'taxonomy_since_version' => 'wp-parser-since',
			)
		);

		foreach ( $r as $property_name => $value ) {
			$this->{$property_name} = $value;
		}
	}

	protected function insert_term( $term, $taxonomy, $args = array() ) {
		if ( isset( $this->inserted_terms[ $taxonomy ][ $term ] ) ) {
			return $this->inserted_terms[ $taxonomy ][ $term ];
		}

		$parent = isset( $args['parent'] ) ? $args['parent'] : 0;
		if ( ! $inserted_term = term_exists( $term, $taxonomy, $parent ) ) {
			$inserted_term = wp_insert_term( $term, $taxonomy, $args );
		}

		if ( ! is_wp_error( $inserted_term ) ) {
			$this->inserted_terms[ $taxonomy ][ $term ] = $inserted_term;
		}

		return $inserted_term;
	}

	/**
	 * For a specific file, go through and import the file, functions, and classes.
	 *
	 * @param array $file
	 * @param bool  $skip_sleep      Optional; defaults to false. If true, the sleep() calls are skipped.
	 * @param bool  $import_internal Optional; defaults to false. If true, functions and classes marked `@internal` will be imported.
	 */
	public function import_file( array $file, $skip_sleep = false, $import_internal = false ) {

		// Maybe add this file to the file taxonomy
		$slug = sanitize_title( str_replace( '/', '_', $file['path'] ) );
		$term = get_term_by( 'slug', $slug, $this->taxonomy_file, ARRAY_A );

		if ( ! $term ) {

			$term = wp_insert_term( $file['path'], $this->taxonomy_file, array( 'slug' => $slug ) );

			if ( is_wp_error( $term ) ) {
				$this->errors[] = sprintf( 'Problem creating file tax item "%1$s" for %2$s: %3$s', $slug, $file['path'], $term->get_error_message() );

				return;
			}

			// Grab the full term object
			$term = get_term_by( 'slug', $slug, $this->taxonomy_file, ARRAY_A );
		}

		// Store file meta for later use
		$this->file_meta = array(
			'docblock' => $file['file'], // File docblock
			'term_id'  => $term['name'], // File's term item in the file taxonomy
		);

		// Functions
		if ( ! empty( $file['functions'] ) ) {
			$i = 0;

			foreach ( $file['functions'] as $function ) {
				$this->import_function( $function, 0, $import_internal );
				$i ++;

				// Wait 3 seconds after every 10 items
				if ( ! $skip_sleep && $i % 10 == 0 ) {
					sleep( 3 );
				}
			}
		}

		// Classes
		if ( ! empty( $file['classes'] ) ) {
			$i = 0;

			foreach ( $file['classes'] as $class ) {
				$this->import_class( $class, $import_internal );
				$i ++;

				// Wait 3 seconds after every 10 items
				if ( ! $skip_sleep && $i % 10 == 0 ) {
					sleep( 3 );
				}
			}
		}

		if ( ! empty( $file['hooks'] ) ) {
			$i = 0;

			foreach ( $file['hooks'] as $hook ) {
				$this->import_hook( $hook, 0, $import_internal );
				$i ++;

				// Wait 3 seconds after every 10 items
				if ( ! $skip_sleep && $i % 10 == 0 ) {
					sleep( 3 );
				}
			}
		}
	}

	/**
	 * Create a post for a function
	 *
	 * @param array $data            Function
	 * @param int   $parent_post_id  Optional; post ID of the parent (class or function) this item belongs to. Defaults to zero (no parent).
	 * @param bool  $import_internal Optional; defaults to false. If true, functions marked `@internal` will be imported.
	 *
	 * @return bool|int Post ID of this function, false if any failure.
	 */
	public function import_function( array $data, $parent_post_id = 0, $import_internal = false ) {
		$function_id = $this->import_item( $data, $parent_post_id, $import_internal );

		foreach ( $data['hooks'] as $hook ) {
			$this->import_hook( $hook, $function_id, $import_internal );
		}
	}

	/**
	 * Create a post for a hook
	 *
	 * @param array $data            Hook
	 * @param int   $parent_post_id  Optional; post ID of the parent (function) this item belongs to. Defaults to zero (no parent).
	 * @param bool  $import_internal Optional; defaults to false. If true, hooks marked `@internal` will be imported.
	 *
	 * @return bool|int Post ID of this hook, false if any failure.
	 */
	public function import_hook( array $data, $parent_post_id = 0, $import_internal = false ) {
		if ( 0 === strpos( $data['doc']['description'], 'This action is documented in' ) ) {
			return false;
		} elseif ( 0 === strpos( $data['doc']['description'], 'This filter is documented in' ) ) {
			return false;
		} elseif ( '' === $data['doc']['description'] && '' === $data['doc']['long_description'] ) {
			return false;
		}

		$hook_id = $this->import_item( $data, $parent_post_id, $import_internal, array( 'post_type' => $this->post_type_hook ) );

		if ( ! $hook_id ) {
			return false;
		}

		update_post_meta( $hook_id, '_wp-parser_hook_type', $data['type'] );

		return $hook_id;
	}

	/**
	 * Create a post for a class
	 *
	 * @param array $data            Class
	 * @param bool  $import_internal Optional; defaults to false. If true, functions marked `@internal` will be imported.
	 *
	 * @return bool|int Post ID of this function, false if any failure.
	 */
	protected function import_class( array $data, $import_internal = false ) {

		// Insert this class
		$class_id = $this->import_item( $data, 0, $import_internal, array( 'post_type' => $this->post_type_class ) );

		if ( ! $class_id ) {
			return false;
		}

		// Set class-specific meta
		update_post_meta( $class_id, '_wp-parser_final', (string) $data['final'] );
		update_post_meta( $class_id, '_wp-parser_abstract', (string) $data['abstract'] );
		update_post_meta( $class_id, '_wp-parser_extends', $data['extends'] );
		update_post_meta( $class_id, '_wp-parser_implements', $data['implements'] );
		update_post_meta( $class_id, '_wp-parser_properties', $data['properties'] );

		// Now add the methods
		foreach ( $data['methods'] as $method ) {
			// Namespace method names with the class name
			$method['name'] = $data['name'] . '::' . $method['name'];
			$this->import_method( $method, $class_id, $import_internal );
		}

		return $class_id;
	}

	/**
	 * Create a post for a class method.
	 *
	 * @param array $data            Method.
	 * @param int   $parent_post_id  Optional; post ID of the parent (class) this
	 *                               method belongs to. Defaults to zero (no parent).
	 * @param bool  $import_internal Optional; defaults to false. If true, functions
	 *                               marked `@internal` will be imported.
	 * @return bool|int Post ID of this function, false if any failure.
	 */
	protected function import_method( array $data, $parent_post_id = 0, $import_internal = false ) {

		// Insert this method.
		$method_id = $this->import_item( $data, $parent_post_id, $import_internal, array( 'post_type' => $this->post_type_method ) );

		if ( ! $method_id ) {
			return false;
		}

		// Set method-specific meta.
		update_post_meta( $method_id, '_wp-parser_final', (string) $data['final'] );
		update_post_meta( $method_id, '_wp-parser_abstract', (string) $data['abstract'] );
		update_post_meta( $method_id, '_wp-parser_static', (string) $data['static'] );
		update_post_meta( $method_id, '_wp-parser_visibility', $data['visibility'] );

		// Now add the hooks.
		if ( ! empty( $data['hooks'] ) ) {
			foreach ( $data['hooks'] as $hook ) {
				$this->import_hook( $hook, $method_id, $import_internal );
			}
		}

		return $method_id;
	}

	/**
	 * Create a post for an item (a class or a function).
	 *
	 * Anything that needs to be dealt identically for functions or methods should go in this function.
	 * Anything more specific should go in either import_function() or import_class() as appropriate.
	 *
	 * @param array $data            Data
	 * @param int   $parent_post_id  Optional; post ID of the parent (class or function) this item belongs to. Defaults to zero (no parent).
	 * @param bool  $import_internal Optional; defaults to false. If true, functions or classes marked `@internal` will be imported.
	 * @param array $arg_overrides   Optional; array of parameters that override the defaults passed to wp_update_post().
	 *
	 * @return bool|int Post ID of this item, false if any failure.
	 */
	public function import_item( array $data, $parent_post_id = 0, $import_internal = false, array $arg_overrides = array() ) {

		/** @var \wpdb $wpdb */
		global $wpdb;

		// Don't import items marked `@internal` unless explicitly requested. See https://github.com/rmccue/WP-Parser/issues/16
		if ( ! $import_internal && wp_list_filter( $data['doc']['tags'], array( 'name' => 'internal' ) ) ) {

			switch ( $post_data['post_type'] ) {
				case $this->post_type_class:
					WP_CLI::log( "\t" . sprintf( 'Skipped importing @internal class "%1$s"', $data['name'] ) );
					break;

				case $this->post_type_method:
					WP_CLI::log( "\t\t" . sprintf( 'Skipped importing @internal method "%1$s"', $data['name'] ) );
					break;

				case $this->post_type_hook:
					$indent = ( $parent_post_id ) ? "\t\t" : "\t";
					WP_CLI::log( $indent . sprintf( 'Skipped importing @internal hook "%1$s"', $data['name'] ) );
					break;

				default:
					WP_CLI::log( "\t" . sprintf( 'Skipped importing @internal function "%1$s"', $data['name'] ) );
			}

			return false;
		}

		if ( wp_list_filter( $data['doc']['tags'], array( 'name' => 'ignore' ) ) ) {
			return false;
		}

		$is_new_post = true;
		$slug        = sanitize_title( $data['name'] );
		$post_data   = wp_parse_args(
			$arg_overrides,
			array(
				'post_content' => $data['doc']['long_description'],
				'post_excerpt' => $data['doc']['description'],
				'post_name'    => $slug,
				'post_parent'  => (int) $parent_post_id,
				'post_status'  => 'publish',
				'post_title'   => $data['name'],
				'post_type'    => $this->post_type_function,
			)
		);

		// Look for an existing post for this item
		$existing_post_id = $wpdb->get_var(
			$q = $wpdb->prepare(
				"SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s AND post_parent = %d LIMIT 1",
				$slug,
				$post_data['post_type'],
				(int) $parent_post_id
			)
		);

		// Insert/update the item post
		if ( ! empty( $existing_post_id ) ) {
			$is_new_post     = false;
			$ID = $post_data['ID'] = (int) $existing_post_id;
			$post_needed_update = array_diff_assoc( sanitize_post( $post_data, 'db' ), get_post( $existing_post_id, ARRAY_A, 'db' ) );
			if ( $post_needed_update ) {
				$ID = wp_update_post( wp_slash( $post_data ), true );
			}
		} else {
			$ID = wp_insert_post( wp_slash( $post_data ), true );
		}
		$anything_updated = array();

		if ( ! $ID || is_wp_error( $ID ) ) {

			switch ( $post_data['post_type'] ) {
				case $this->post_type_class:
					$this->errors[] = "\t" . sprintf( 'Problem inserting/updating post for class "%1$s"', $data['name'], $ID->get_error_message() );
					break;

				case $this->post_type_method:
					$this->errors[] = "\t\t" . sprintf( 'Problem inserting/updating post for method "%1$s"', $data['name'], $ID->get_error_message() );
					break;

				case $this->post_type_hook:
					$indent = ( $parent_post_id ) ? "\t\t" : "\t";
					$this->errors[] = $indent . sprintf( 'Problem inserting/updating post for hook "%1$s"', $data['name'], $ID->get_error_message() );
					break;

				default:
					$this->errors[] = "\t" . sprintf( 'Problem inserting/updating post for function "%1$s"', $data['name'], $ID->get_error_message() );
			}

			return false;
		}

		// If the item has @since markup, assign the taxonomy
		$since_version = wp_list_filter( $data['doc']['tags'], array( 'name' => 'since' ) );
		if ( ! empty( $since_version ) ) {

			$since_version = array_shift( $since_version );
			$since_version = $since_version['content'];
			$since_term    = term_exists( $since_version, $this->taxonomy_since_version );

			if ( ! $since_term ) {
				$since_term = wp_insert_term( $since_version, $this->taxonomy_since_version );
			}

			// Assign the tax item to the post
			if ( ! is_wp_error( $since_term ) ) {
				wp_set_object_terms( $ID, (int) $since_term['term_id'], $this->taxonomy_since_version );
			} else {
				WP_CLI::warning( "\tCannot set @since term: " . $since_term->get_error_message() );
			}
		}

		$packages = array(
			'main' => wp_list_filter( $data['doc']['tags'], array( 'name' => 'package' ) ),
			'sub'  => wp_list_filter( $data['doc']['tags'], array( 'name' => 'subpackage' ) ),
		);

		// If the @package/@subpackage is not set by the individual function or class, get it from the file scope
		if ( empty( $packages['main'] ) ) {
			$packages['main'] = wp_list_filter( $this->file_meta['docblock']['tags'], array( 'name' => 'package' ) );
		}

		if ( empty( $packages['sub'] ) ) {
			$packages['sub'] = wp_list_filter( $this->file_meta['docblock']['tags'], array( 'name' => 'subpackage' ) );
		}

		$main_package_id   = false;
		$package_term_args = array();

		// If the item has any @package/@subpackage markup (or has inherited it from file scope), assign the taxonomy.
		foreach ( $packages as $pack_name => $pack_value ) {

			if ( empty( $pack_value ) ) {
				continue;
			}

			$pack_value = array_shift( $pack_value );
			$pack_value = $pack_value['content'];

			// Set the parent term_id to look for, as the package taxonomy is hierarchical.
			if ( $pack_name === 'sub' && is_int( $main_package_id ) ) {
				$package_term_args = array( 'parent' => $main_package_id );
			} else {
				$package_term_args = array( 'parent' => 0 );
			}

			// If the package doesn't already exist in the taxonomy, add it
			$package_term = term_exists( $pack_value, $this->taxonomy_package, $package_term_args['parent'] );
			if ( ! $package_term ) {
				$package_term = wp_insert_term( $pack_value, $this->taxonomy_package, $package_term_args );
			}

			if ( $pack_name === 'main' && $main_package_id === false && ! is_wp_error( $package_term ) ) {
				$main_package_id = (int) $package_term['term_id'];
			}

			// Assign the tax item to the post
			if ( ! is_wp_error( $package_term ) ) {
				wp_set_object_terms( $ID, (int) $package_term['term_id'], $this->taxonomy_package );
			} elseif ( is_int( $main_package_id ) ) {
				WP_CLI::warning( "\tCannot set @subpackage term: " . $package_term->get_error_message() );
			} else {
				WP_CLI::warning( "\tCannot set @package term: " . $package_term->get_error_message() );
			}
		}

		// Set other taxonomy and post meta to use in the theme templates
		wp_set_object_terms( $ID, $this->file_meta['term_id'], $this->taxonomy_file );
		if ( $post_data['post_type'] !== $this->post_type_class ) {
			update_post_meta( $ID, '_wp-parser_args', $data['arguments'] );
		}
		update_post_meta( $ID, '_wp-parser_line_num', $data['line'] );
		update_post_meta( $ID, '_wp-parser_end_line_num', $data['end_line'] );
		update_post_meta( $ID, '_wp-parser_tags', $data['doc']['tags'] );

		// Everything worked! Woo hoo!
		if ( $is_new_post ) {
			switch ( $post_data['post_type'] ) {
				case $this->post_type_class:
					WP_CLI::log( "\t" . sprintf( 'Imported class "%1$s"', $data['name'] ) );
					break;

				case $this->post_type_hook:
					$indent = ( $parent_post_id ) ? "\t\t" : "\t";
					WP_CLI::log( $indent . sprintf( 'Imported hook "%1$s"', $data['name'] ) );
					break;

				case $this->post_type_method:
					WP_CLI::log( "\t\t" . sprintf( 'Imported method "%1$s"', $data['name'] ) );
					break;

				default:
					WP_CLI::log( "\t" . sprintf( 'Imported function "%1$s"', $data['name'] ) );
			}
		} else {
			switch ( $post_data['post_type'] ) {
				case $this->post_type_class:
					WP_CLI::log( "\t" . sprintf( 'Updated class "%1$s"', $data['name'] ) );
					break;

				case $this->post_type_hook:
					$indent = ( $parent_post_id ) ? "\t\t" : "\t";
					WP_CLI::log( $indent . sprintf( 'Updated hook "%1$s"', $data['name'] ) );
					break;

				case $this->post_type_method:
					WP_CLI::log( "\t\t" . sprintf( 'Updated method "%1$s"', $data['name'] ) );
					break;

				default:
					WP_CLI::log( "\t" . sprintf( 'Updated function "%1$s"', $data['name'] ) );
			}
		}

		return $ID;
	}
}
