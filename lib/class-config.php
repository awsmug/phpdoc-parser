<?php
namespace WP_Parser;

/**
 * Plugin configuration class. Stored persistently via options.
 */
final class Config {

	private $defaults = array(
		'hook_prefix' => '',
		'namespace'   => '',
		'version'     => '',
	);

	private static $instance;

	public static function getInstance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function get( $key ) {
		$option = $this->getOption();

		if ( ! isset( $option[ $key ] ) ) {
			return $this->getDefault( $key );
		}

		return $option[ $key ];
	}

	public function set( $key, $value ) {
		$option = $this->getOption();

		$option[ $key ] = $value;

		return $this->updateOption( $option );
	}

	public function getMultiple( $keys ) {
		$option = $this->getOption();

		$values = array();
		foreach ( $keys as $key ) {
			$values = isset( $option[ $key ] ) ? $option[ $key ] : $this->getDefault( $key );
		}

		return $values;
	}

	public function setMultiple( $values ) {
		$option = $this->getOption();

		foreach ( $values as $key => $value ) {
			$option[ $key ] = $value;
		}

		return $this->updateOption( $option );
	}

	public function getList() {
		$option = $this->getOption();

		return wp_parse_args( $option, $this->defaults );
	}

	private function getDefault( $key ) {
		return isset( $this->defaults[ $key ] ) ? $this->defaults[ $key ] : false;
	}

	private function getOption() {
		return get_option( 'wp_parser_config', array() );
	}

	private function updateOption( $config ) {
		return update_option( 'wp_parser_config', $config );
	}
}
