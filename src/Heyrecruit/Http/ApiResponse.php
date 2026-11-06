<?php
declare(strict_types=1);

namespace Heyrecruit\Http;

/**
 * Ergebnis eines API-Aufrufs.
 */
final class ApiResponse {

	/**
	 * @param int         $statusCode HTTP status, 0 if the request never completed.
	 * @param array|null  $data       Decoded response body, null if it was not valid JSON.
	 * @param string|null $error      Transport or decoding error, null on success.
	 */
	public function __construct(
		public readonly int $statusCode,
		public readonly ?array $data = null,
		public readonly ?string $error = null
	) {
	}

	/**
	 * Erzeugt eine Antwort fuer einen Request, der die Gegenstelle nie erreicht hat.
	 *
	 * @param string $error The transport error.
	 *
	 * @return self
	 */
	public static function transportError(string $error): self {
		return new self(0, null, $error);
	}

	/**
	 * @return bool
	 */
	public function isSuccess(): bool {
		return $this->error === null && $this->statusCode >= 200 && $this->statusCode < 300;
	}

	/**
	 * @return bool
	 */
	public function isUnauthorized(): bool {
		return $this->statusCode === 401;
	}

	/**
	 * Prueft, ob die Gegenstelle den Token als abgelaufen gemeldet hat.
	 *
	 * @return bool
	 */
	public function isExpiredToken(): bool {
		return $this->isUnauthorized() && ($this->data['errors'] ?? null) === 'Expired token';
	}

	/**
	 * Liefert die Fehlermeldung der API, sonst den Transportfehler, sonst den Status.
	 *
	 * Die heyrecruit-API legt Fehler unter 'errors' ab (AppService::sendJsonResponse baut
	 * {status, errors|data, detail}). Unter 'message' steht eine maschinenlesbare Kennung, etwa
	 * 'company_inactive' bei einer deaktivierten Firma; Konsumenten erkennen den Fall an ihr, deshalb
	 * steht sie mit in der Meldung.
	 *
	 * @return string
	 */
	public function describeFailure(): string {
		$parts = [];

		foreach (['errors', 'message'] as $key) {
			$value = $this->data[$key] ?? null;

			if (is_string($value) && $value !== '' && !in_array($value, $parts, true)) {
				$parts[] = $value;
			}
		}

		if ($parts !== []) {
			return count($parts) === 1 ? $parts[0] : $parts[0] . ' (' . $parts[1] . ')';
		}

		return $this->error ?? 'HTTP ' . $this->statusCode;
	}

	/**
	 * Antwortform des SDK bis 2.x - Konsumenten lesen 'response' und 'status_code'.
	 *
	 * @return array
	 */
	public function toArray(): array {
		return [
			'response'    => $this->data,
			'status_code' => $this->statusCode,
			'error'       => $this->error,
		];
	}
}
