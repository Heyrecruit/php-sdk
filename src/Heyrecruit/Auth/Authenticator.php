<?php
declare(strict_types=1);

namespace Heyrecruit\Auth;

use Exception;
use Heyrecruit\Http\Transport;
use InvalidArgumentException;

/**
 * Tauscht client_id und client_secret gegen ein JWT und haelt es aktuell.
 */
final class Authenticator {

	public const TOKEN_EXPIRY_MARGIN_SECONDS = 60;

	private const AUTH_PATH = 'auth';

	/** @var array{client_id: mixed, client_secret: mixed} */
	private array $credentials;

	/**
	 * @param Transport  $transport   The HTTP boundary.
	 * @param TokenStore $store       Where the token is remembered.
	 * @param array      $config      Configuration carrying SCOPE_CLIENT_ID and SCOPE_CLIENT_SECRET.
	 */
	public function __construct(
		private readonly Transport $transport,
		private readonly TokenStore $store,
		array $config
	) {
		$this->setCredentials($config);
	}

	/**
	 * @param array $config Configuration carrying SCOPE_CLIENT_ID and SCOPE_CLIENT_SECRET.
	 *
	 * @return void
	 * @throws InvalidArgumentException
	 */
	public function setCredentials(array $config): void {
		if (!isset($config['SCOPE_CLIENT_ID'])) {
			throw new InvalidArgumentException('Missing CLIENT_ID parameter.');
		}

		if (!isset($config['SCOPE_CLIENT_SECRET'])) {
			throw new InvalidArgumentException('Missing CLIENT_SECRET parameter.');
		}

		$credentials = [
			'client_id'     => $config['SCOPE_CLIENT_ID'],
			'client_secret' => $config['SCOPE_CLIENT_SECRET'],
		];

		// Nur ein echter Wechsel verwirft den Store - im Konstruktor wuerde das sonst bei
		// jedem Request den Session-Cache leeren und ihn damit wirkungslos machen.
		$changed = isset($this->credentials) && $this->credentials !== $credentials;

		$this->credentials = $credentials;

		if ($changed) {
			$this->store->clear();
		}
	}

	/**
	 * Liefert ein gueltiges Token, notfalls per neuer Authentifizierung.
	 *
	 * @param bool $force Ignore the store and authenticate again.
	 *
	 * @return AccessToken
	 * @throws Exception if the API refuses the credentials or is unreachable.
	 */
	public function token(bool $force = false): AccessToken {
		if (!$force) {
			$cached = $this->store->get();

			if ($cached !== null) {
				return $cached;
			}
		}

		$response = $this->transport->postForm(self::AUTH_PATH, $this->credentials);
		$payload  = $response->data;

		if (($payload['status'] ?? null) === 'success' && is_array($payload['data'] ?? null)) {
			$token = AccessToken::fromArray($payload['data'], self::TOKEN_EXPIRY_MARGIN_SECONDS);

			if ($token !== null) {
				$this->store->set($token);

				return $token;
			}
		}

		throw new Exception('Auth error! Message from Heyrecruit: ' . $response->describeFailure());
	}

	/**
	 * Verwirft das gespeicherte Token.
	 *
	 * @return void
	 */
	public function forget(): void {
		$this->store->clear();
	}
}
