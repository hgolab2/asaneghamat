<?php
/**
 * Implementation of the Rsssl_Array_Query_Builder, an intermediary layer that
 * allows arrays to mimic database request behaviors suitable for Datatables.
 *
 * @package ReallySimplePlugins
 * @author Marcel Santing
 * @version 1.0
 */

namespace REALLY_SIMPLE_SSL\Security\DynamicTables;

require_once rsssl_path . 'pro/security/dynamic-tables/class-rsssl-abstract-database-manager.php';

/**
 * Rsssl_Array_Query_Builder serves as an intermediary layer for arrays,
 * enabling them to mimic behaviors of database requests. Designed to
 * integrate seamlessly with Datatables, it provides functionality similar to
 * database operations but works directly on arrays.
 *
 * @package ReallySimplePlugins
 * @author Marcel Santing
 */
class Rsssl_Array_Query_Builder extends Rsssl_Abstract_Database_Manager {

	/**
	 * Data array to be processed.
	 *
	 * @var array
	 */
	private $data_array;

	/**
	 * Array of where values.
	 *
	 * @var array
	 */
	protected $where = array();

	/**
	 * String values for order of data.
	 *
	 * @var string
	 */
	protected $order_by;

	/**
	 * The Constructor class.
	 *
	 * @param array $data_array The Array of processable data.
	 */
	public function __construct( $data_array ) {
		parent::__construct( '' );
		$this->data_array = $data_array;
	}

	/**
	 * Fetches the results of the Data.
	 *
	 * @return array
	 */
	public function get(): array {
		$results = $this->data_array;

		// Applying WHERE conditions including where_in and where_not_in.
		if ( ! empty( $this->where ) ) {
			$results = array_filter(
				$results,
				function ( $item ) {
					foreach ( $this->where as $where_clause ) {
						$column   = $where_clause['column'];
						$operator = $where_clause['operator'];
						$value    = $where_clause['value'];

						if ( ! $this->compare( $item[ $column ], $operator, $value ) ) {
							return false;
						}
					}
					return true;
				}
			);
		}

		// Applying ORDER BY.
		if ( ! empty( $this->order_by ) ) {
			[ $column, $direction ] = explode( ' ', $this->order_by );
			usort(
				$results,
				static function ( $a, $b ) use ( $column, $direction ) {
					if ( $a[ $column ] === $b[ $column ] ) {
						return 0;
					}
					if ( 'ASC' === $direction ) {
						return ( $a[ $column ] < $b[ $column ] ) ? -1 : 1;
					}

					return ( $a[ $column ] > $b[ $column ] ) ? -1 : 1;
				}
			);
		}

		// Applying LIMIT and OFFSET.
		if ( ! empty( $this->limit ) ) {
			$results = array_slice( $results, $this->offset, $this->limit );
		}

		return $results;
	}

	/**
	 * Returns the count processed data.
	 *
	 * @return string|null
	 */
	public function count(): ?string {
		return count( $this->get() );
	}

	/**
	 * Since this is not an actual database query builder we return a string.
	 *
	 * @return string|null
	 */
	public function to_sql(): ?string {
		return 'Not available for ArrayQueryBuilder.';
	}

	/**
	 * This is used for comparisons with operator strings
	 *
	 * @param  string $item_value The value of the item.
	 * @param  string $operator The operator to use.
	 * @param  string $value The value to compare with.
	 *
	 * @return bool
	 */
	private function compare( string $item_value, string $operator, string $value ): bool {
		switch ( $operator ) {
			case '=':
				return $item_value === $value;
			case '!=':
				return $item_value !== $value;
			case '>':
				return $item_value > $value;
			case '<':
				return $item_value < $value;
			case '>=':
				return $item_value >= $value;
			case '<=':
				return $item_value <= $value;
			case 'LIKE':
				return stripos( $item_value, trim( $value, '%' ) ) !== false;
			case 'NOT LIKE':
				return stripos( $item_value, trim( $value, '%' ) ) === false;
			case 'IN':
				return in_array( $item_value, (array) $value, true );
			case 'NOT IN':
				return ! in_array( $item_value, (array) $value, true );
			default:
				return false;
		}
	}

	/**
	 * Sets the columns for selection in the query. Not applicable for ArrayQueryBuilder.
	 *
	 * @param  bool $skip_limit Whether to skip the limit.
	 *
	 * @return string|null
	 */
	public function get_query( bool $skip_limit = false ): ?string {
		return 'Not applicable for ArrayQueryBuilder.';
	}

	/**
	 * Fetches the columns from the queryBuilder.
	 *
	 * @return array
	 */
	public function get_columns(): array {
		if ( ! empty( $this->data_array ) ) {
			return array_keys( $this->data_array[0] );
		}
		return array();
	}

	/**
	 * Builds a Where In clause.
	 *
	 * @param  string  $column The column to use.
	 * @param  array  $values The values to use.
	 *
	 * @return Rsssl_Array_Query_Builder
	 */
	public function where_in( string $column, array $values ): Rsssl_Array_Query_Builder {
		$this->where[] = array(
			'column'   => $column,
			'operator' => 'IN',
			'value'    => $values,
		);
		return $this;
	}

	/**
	 * Builds a Where Not In clause.
	 *
	 * @param  string  $column The column to use.
	 * @param  array  $values The values to use.
	 *
	 * @return Rsssl_Array_Query_Builder
	 */
	public function where_not_in( string $column, array $values ): Rsssl_Array_Query_Builder {
		$this->where[] = array(
			'column'   => $column,
			'operator' => 'NOT IN',
			'value'    => $values,
		);
		return $this;
	}
}
