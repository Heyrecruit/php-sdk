<?php
declare(strict_types=1);

namespace Heyrecruit\Auth;

/**
 * Ablage fuer das Zugriffstoken zwischen zwei Requests.
 */
interface TokenStore {

	/**
	 * Liefert ein noch gueltiges Token, sonst null.
	 *
	 * @return AccessToken|null
	 */
	public function get(): ?AccessToken;

	/**
	 * @param AccessToken $token The token to remember.
	 *
	 * @return void
	 */
	public function set(AccessToken $token): void;

	/**
	 * @return void
	 */
	public function clear(): void;
}
