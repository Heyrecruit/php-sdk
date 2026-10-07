<?php
declare(strict_types=1);

namespace Heyrecruit\Auth;

/**
 * Legt das Token in der PHP-Session ab. Ohne laufende Session ist der Store wirkungslos.
 */
final class SessionTokenStore implements TokenStore {

	public const DEFAULT_SESSION_KEY = 'HEY_AUTH';

	/**
	 * @param string $sessionKey The key inside $_SESSION.
	 */
	public function __construct(private readonly string $sessionKey = self::DEFAULT_SESSION_KEY) {
	}

	/**
	 * @inheritDoc
	 */
	public function get(): ?AccessToken {
		if (!$this->hasSession()) {
			return null;
		}

		$stored = $_SESSION[$this->sessionKey] ?? null;

		if (!is_array($stored)) {
			return null;
		}

		$token = AccessToken::fromArray($stored);

		return $token !== null && !$token->isExpired() ? $token : null;
	}

	/**
	 * @inheritDoc
	 */
	public function set(AccessToken $token): void {
		if ($this->hasSession()) {
			$_SESSION[$this->sessionKey] = $token->toArray();
		}
	}

	/**
	 * @inheritDoc
	 */
	public function clear(): void {
		if ($this->hasSession()) {
			unset($_SESSION[$this->sessionKey]);
		}
	}

	/**
	 * @return bool
	 */
	private function hasSession(): bool {
		return session_status() === PHP_SESSION_ACTIVE;
	}
}
