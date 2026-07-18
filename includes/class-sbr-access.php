<?php
/**
 * Access control: decides whether a user may read a given book.
 *
 * Rule: the user must be logged in and have a COMPLETED WooCommerce order
 * containing the product. Admins/editors of the product can always read
 * (so the store owner can preview books).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SBR_Access {

	/**
	 * Whether the given user may read the given book (product).
	 *
	 * @param int $user_id    WordPress user ID.
	 * @param int $product_id WooCommerce product ID.
	 * @return bool
	 */
	public static function can_read( $user_id, $product_id ) {
		$user_id    = absint( $user_id );
		$product_id = absint( $product_id );

		if ( ! $user_id || ! $product_id ) {
			return false;
		}

		// Store staff who can edit the product may always preview it.
		if ( user_can( $user_id, 'edit_post', $product_id ) ) {
			return true;
		}

		return self::has_bought_book( $user_id, $product_id );
	}

	/**
	 * Whether the user has a completed order containing this product.
	 * Checks orders owned by the account and orders placed with the same
	 * billing email (e.g. guest checkout before the account existed).
	 */
	public static function has_bought_book( $user_id, $product_id ) {
		static $cache = array();

		$key = $user_id . ':' . $product_id;

		if ( isset( $cache[ $key ] ) ) {
			return $cache[ $key ];
		}

		$user = get_userdata( $user_id );

		if ( ! $user ) {
			$cache[ $key ] = false;
			return false;
		}

		$order_ids = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'wc-completed' ),
				'limit'       => 200,
				'return'      => 'ids',
			)
		);

		$email_order_ids = wc_get_orders(
			array(
				'billing_email' => $user->user_email,
				'status'        => array( 'wc-completed' ),
				'limit'         => 200,
				'return'        => 'ids',
			)
		);

		$order_ids = array_unique( array_merge( (array) $order_ids, (array) $email_order_ids ) );
		$found     = false;

		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			foreach ( $order->get_items() as $item ) {
				$ids = array( (int) $item->get_product_id(), (int) $item->get_variation_id() );

				if ( in_array( (int) $product_id, $ids, true ) ) {
					$found = true;
					break 2;
				}
			}
		}

		$cache[ $key ] = $found;

		return $found;
	}
}
