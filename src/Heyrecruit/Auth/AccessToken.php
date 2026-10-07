<?php
declare(strict_types=1);

namespace Heyrecruit\Auth;

/**
 * JWT-Zugriffstoken mit seiner Gueltigkeit.
 */
final class AccessToken {

	/**
	 * @param string $token     The JWT.
	 * @param int    $expiresAt Unix timestamp at which the token stops being used.
	 */
	public function __construct(
		public readonly string $token,
		public readonly int $expiresAt
	) {
	}

	/**
	 * Baut ein Token aus der API-Antwort und zieht den Sicherheitsabstand hier einmalig ab.
	 *
	 * @param array $data           The 'data' section of the auth response.
	 * @param int   $marginSeconds  Seconds to retire the token early.
	 *
	 * @return self|null Null if the payload carries no usable token.
	 */
	public static function fromArray(array $data, int $marginSeconds = 0): ?self {
		$token = $data['token'] ?? null;

		// Steuerzeichen abweisen: der Wert geht als Authorization-Header raus, und libcurl schreibt
		// ein CR/LF darin ungefiltert durch - damit waere eine zweite Header-Zeile moeglich.
		if (!is_string($token) || $token === '' || preg_match('/[\x00-\x1F\x7F]/', $token) === 1) {
			return null;
		}

		return new self($token, (int)($data['expiration'] ?? 0) - $marginSeconds);
	}

	/**
	 * @return bool
	 */
	public function isExpired(): bool {
		return $this->expiresAt <= time();
	}

	/**
	 * Speicherform des Tokens - identisch zur Session-Struktur bis 2.x.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return ['token' => $this->token, 'expiration' => $this->expiresAt];
	}
}
