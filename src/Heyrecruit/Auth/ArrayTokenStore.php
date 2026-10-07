<?php
declare(strict_types=1);

namespace Heyrecruit\Auth;

/**
 * Haelt das Token nur fuer die Lebensdauer der Instanz - fuer CLI, Cron und Tests.
 */
final class ArrayTokenStore implements TokenStore {

	private ?AccessToken $token = null;

	/**
	 * @inheritDoc
	 */
	public function get(): ?AccessToken {
		return $this->token !== null && !$this->token->isExpired() ? $this->token : null;
	}

	/**
	 * @inheritDoc
	 */
	public function set(AccessToken $token): void {
		$this->token = $token;
	}

	/**
	 * @inheritDoc
	 */
	public function clear(): void {
		$this->token = null;
	}
}
