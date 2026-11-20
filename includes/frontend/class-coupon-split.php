<?php
/**
 * Splits a fixed cart coupon by the price of each line.
 *
 * @package RhinnoIT\Customizer
 */

namespace RhinnoIT\Customizer\Frontend;

use RhinnoIT\Customizer\Logger;
use RhinnoIT\Customizer\Settings;

defined( 'ABSPATH' ) || exit;

/**
 * Shares a fixed cart coupon out by price, so a cheap line never ends up free.
 *
 * WooCommerce splits a fixed cart coupon evenly per unit
 * (WC_Discounts::apply_coupon_fixed_cart(), the same from 3.2 to 11.2): each
 * unit gets the amount over the number of units, no line gets more than what
 * is left of it, and what a line could not take goes round the others the
 * same way. A 10,000 coupon on a 179,990 pair of shoes and 4,990 socks takes
 * 5,010 off the shoes and all 4,990 off the socks, which reach the order at
 * 0, and so does every refund, invoice line or store's takings read from it.
 * Split by price, the shoes get 9,730 and the socks 270.
 *
 * WooCommerce has no hook for the split itself. Right before it, WC_Discounts
 * passes woocommerce_coupon_get_items_to_apply (8.8+) the items it is about
 * to discount and itself, and it keeps every split in one table, its
 * protected $discounts (coupon code => item key => cents, the same since 3.2),
 * which is all that is read afterwards: line totals, the coupon's total and
 * tax, an order's items. Last on that filter, this adds its own split to the
 * table and returns no items, which leaves WooCommerce's even split nothing to
 * do. The cart, both checkouts and an order whose coupons are applied, removed
 * or recalculated in the admin all total through WC_Discounts, so they all get
 * the same split. Orders already placed keep the totals they were saved with.
 *
 * A line weighs its price, or what earlier coupons left of it with "Calculate
 * coupon discounts sequentially" on, as with WooCommerce's percent coupons.
 * Amounts are whole cents (pesos in CLP): each line gets the floor of its
 * share and the cents those floors leave go to the largest remainders, so the
 * lines add up to the coupon. A line never gets more than what is left of it;
 * the rest goes to the others by price, and a coupon worth more than the cart
 * frees every line, as in WooCommerce.
 *
 * Only fixed cart coupons: percent ones already take the same share of every
 * line, and fixed product ones are per unit on purpose. A coupon with no
 * amount, an item list WooCommerce would not have built, a table not shaped
 * as expected or a plugin that changes these amounts (see accepted()) is left
 * to WooCommerce's own split.
 */
final class Coupon_Split {

	/**
	 * The WooCommerce version whose WC_Discounts first passes itself to woocommerce_coupon_get_items_to_apply.
	 */
	const string SINCE = '8.8.0';

	/**
	 * Hooks the split while the switch is on and WooCommerce hands over what it needs.
	 *
	 * @return void
	 */
	public function register(): void {
		if ( ! Settings::feature( 'cart_coupon_split' ) || ! self::can_split() ) {
			return;
		}

		add_filter( 'woocommerce_coupon_get_items_to_apply', array( $this, 'split' ), PHP_INT_MAX, 3 );
	}

	/**
	 * Whether WooCommerce passes the items over and keeps the table the split goes into.
	 *
	 * Settings::conditions() asks the same, so the switch is offered only where it acts.
	 *
	 * @return bool
	 */
	public static function can_split(): bool {
		return defined( 'WC_VERSION' )
			&& version_compare( (string) WC_VERSION, self::SINCE, '>=' )
			&& class_exists( 'WC_Discounts' )
			&& property_exists( 'WC_Discounts', 'discounts' );
	}

	/**
	 * Splits a fixed cart coupon by price into WooCommerce's table and leaves it no items.
	 *
	 * @param mixed $items     The items WooCommerce is about to discount, cloned from its own.
	 * @param mixed $coupon    The coupon being applied.
	 * @param mixed $discounts The WC_Discounts applying it.
	 * @return mixed The items as they came, or none once the split is in the table.
	 */
	public function split( mixed $items, mixed $coupon, mixed $discounts ): mixed {
		if ( ! is_array( $items ) || ! $coupon instanceof \WC_Coupon || ! $discounts instanceof \WC_Discounts || ! $coupon->is_type( 'fixed_cart' ) ) {
			return $items;
		}

		$amount = (int) round( wc_add_number_precision( (float) $coupon->get_amount() ) );

		if ( 0 >= $amount ) {
			return $items;
		}

		$sequential = 'yes' === get_option( 'woocommerce_calc_discounts_sequentially', 'no' );
		$weights    = array();
		$rooms      = array();
		$lines      = array();

		foreach ( $items as $item ) {
			if ( ! $item instanceof \stdClass || ! isset( $item->key, $item->price ) || ! is_numeric( $item->price ) || ! ( is_int( $item->key ) || is_string( $item->key ) ) || isset( $lines[ $item->key ] ) ) {
				return $items;
			}

			$room   = $discounts->get_discounted_price_in_cents( $item );
			$weight = $sequential ? $room : (int) round( (float) $item->price );

			if ( 0 < $room && 0 < $weight ) {
				$weights[ $item->key ] = $weight;
				$rooms[ $item->key ]   = $room;
				$lines[ $item->key ]   = $item;
			}
		}

		if ( array() === $rooms ) {
			return $items;
		}

		$total = array_sum( $weights );

		// Every product shares() takes stays below max( $amount, $total, a room ) * $total.
		if ( max( $amount, $total, max( $rooms ) ) > intdiv( PHP_INT_MAX, $total ) ) {
			return $items;
		}

		$shares = self::shares( $amount, $weights, $rooms );
		$code   = $coupon->get_code();

		if ( ! self::accepted( $discounts, $coupon, $shares, $lines, $sequential ) ) {
			Logger::error_once( 'rcus_coupon_split_filtered', sprintf( 'Split cart coupons by price stepped aside for coupon "%s": a plugin changes its amounts through woocommerce_coupon_get_discount_amount, so WooCommerce split it its own way.', $code ), DAY_IN_SECONDS );

			return $items;
		}

		if ( ! self::record( $discounts, $code, $shares ) ) {
			Logger::error_once( 'rcus_coupon_split_table', sprintf( 'Split cart coupons by price stepped aside for coupon "%s": WC_Discounts had not started its row of discounts, so WooCommerce split it its own way.', $code ), DAY_IN_SECONDS );

			return $items;
		}

		return array();
	}

	/**
	 * Shares an amount out by weight, never giving a line more than its room.
	 *
	 * A line whose share would not fit takes its room, and the rest is shared
	 * again among the others; once every share fits, each line gets the floor
	 * of its share and the cents left go to the largest remainders, equal ones
	 * in WooCommerce's order (dearest unit first).
	 *
	 * @param int                    $amount  Cents to share out.
	 * @param array<int|string, int> $weights Item key => weight, each above 0.
	 * @param array<int|string, int> $rooms   Item key => most it can take, each above 0.
	 * @return array<int|string, int> Item key => cents: the amount, or every room when they add up to less.
	 */
	private static function shares( int $amount, array $weights, array $rooms ): array {
		$shares = array_fill_keys( array_keys( $weights ), 0 );
		$open   = $weights;
		$left   = $amount;

		while ( 0 < $left && array() !== $open ) {
			$total = array_sum( $open );
			$full  = array_filter(
				$open,
				static fn ( int $weight, int|string $key ): bool => $left * $weight >= $rooms[ $key ] * $total,
				ARRAY_FILTER_USE_BOTH
			);

			if ( array() === $full ) {
				$given      = 0;
				$remainders = array();

				foreach ( $open as $key => $weight ) {
					$shares[ $key ]     = intdiv( $left * $weight, $total );
					$remainders[ $key ] = ( $left * $weight ) % $total;
					$given             += $shares[ $key ];
				}

				arsort( $remainders );

				foreach ( array_slice( array_keys( $remainders ), 0, $left - $given ) as $key ) {
					++$shares[ $key ];
				}

				break;
			}

			foreach ( array_keys( $full ) as $key ) {
				$shares[ $key ] = $rooms[ $key ];
				$left          -= $rooms[ $key ];
				unset( $open[ $key ] );
			}
		}

		return $shares;
	}

	/**
	 * Whether WooCommerce's legacy per-item filter leaves every share as it is.
	 *
	 * In the cart WooCommerce sends its own shares of a fixed cart coupon
	 * through woocommerce_coupon_get_discount_amount, so a plugin can change a
	 * line's part there. One that changes any of these shares owns the
	 * coupon's arithmetic, and WooCommerce keeps it, filter and all. An order's
	 * shares WooCommerce does not send, and neither does this.
	 *
	 * @param \WC_Discounts                $discounts  The WC_Discounts applying the coupon.
	 * @param \WC_Coupon                   $coupon     The coupon.
	 * @param array<int|string, int>       $shares     Item key => cents.
	 * @param array<int|string, \stdClass> $lines      Item key => the item WooCommerce passed.
	 * @param bool                         $sequential Whether earlier coupons lower the price discounted.
	 * @return bool
	 */
	private static function accepted( \WC_Discounts $discounts, \WC_Coupon $coupon, array $shares, array $lines, bool $sequential ): bool {
		if ( ! is_a( $discounts->get_object(), 'WC_Cart' ) || ! has_filter( 'woocommerce_coupon_get_discount_amount' ) ) {
			return true;
		}

		foreach ( $shares as $key => $cents ) {
			$item  = $lines[ $key ];
			$price = $sequential ? $discounts->get_discounted_price_in_cents( $item ) : (float) $item->price;

			/**
			 * A line's part of a coupon, sent as WC_Discounts sends its own in the cart.
			 *
			 * WooCommerce's legacy filter, applied in WC_Discounts and WC_Coupon::get_discount_amount().
			 *
			 * @since 3.2.0
			 */
			$filtered = apply_filters( 'woocommerce_coupon_get_discount_amount', wc_remove_number_precision( $cents ), wc_remove_number_precision( $price ), $item->object ?? null, false, $coupon ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WooCommerce's hook.

			if ( ! is_numeric( $filtered ) || (int) round( wc_add_number_precision( (float) $filtered ) ) !== $cents ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Adds the shares to the coupon's row of WooCommerce's table of discounts.
	 *
	 * WC_Discounts::apply_coupon() starts the row, every item at 0, before it
	 * asks woocommerce_coupon_get_items_to_apply. Without one this is not the
	 * call it should be, and the coupon is left to WooCommerce.
	 *
	 * @param \WC_Discounts          $discounts The WC_Discounts applying the coupon.
	 * @param string                 $code      The coupon's code, which keys its row.
	 * @param array<int|string, int> $shares    Item key => cents.
	 * @return bool Whether the shares went in.
	 */
	private static function record( \WC_Discounts $discounts, string $code, array $shares ): bool {
		$table = new \ReflectionProperty( \WC_Discounts::class, 'discounts' );
		$rows  = $table->getValue( $discounts );

		if ( ! is_array( $rows ) || ! isset( $rows[ $code ] ) || ! is_array( $rows[ $code ] ) ) {
			return false;
		}

		foreach ( $shares as $key => $cents ) {
			$rows[ $code ][ $key ] = (int) ( $rows[ $code ][ $key ] ?? 0 ) + $cents;
		}

		$table->setValue( $discounts, $rows );

		return true;
	}
}
