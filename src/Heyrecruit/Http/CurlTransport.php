<?php
declare(strict_types=1);

namespace Heyrecruit\Http;

use CurlHandle;
use ValueError;

/**
 * cURL-Transport mit Timeouts und ausgewerteten Fehlern.
 */
final class CurlTransport implements Transport {

	public const DEFAULT_CONNECT_TIMEOUT_SECONDS = 5;
	public const DEFAULT_REQUEST_TIMEOUT_SECONDS = 15;

	private string $baseUrl;

	/**
	 * @param string $baseUrl               The API base URL, e.g. https://app.heyrecruit.de/api/v2.
	 * @param int    $connectTimeoutSeconds Connect timeout.
	 * @param int    $requestTimeoutSeconds Overall timeout.
	 */
	public function __construct(
		string $baseUrl,
		private readonly int $connectTimeoutSeconds = self::DEFAULT_CONNECT_TIMEOUT_SECONDS,
		private readonly int $requestTimeoutSeconds = self::DEFAULT_REQUEST_TIMEOUT_SECONDS
	) {
		$this->baseUrl = rtrim($baseUrl, '/');
	}

	/**
	 * @return string
	 */
	public function baseUrl(): string {
		return $this->baseUrl;
	}

	/**
	 * Die cURL-Optionen eines Requests. Oeffentlich, weil sich nur so pruefen laesst, dass die
	 * Timeouts wirklich gesetzt werden - curl bietet keine Introspektion eines Handles.
	 *
	 * @param array $headers Complete header lines.
	 * @param array $extra   Method specific options, taking precedence.
	 *
	 * @return array
	 */
	public function requestOptions(array $headers = [], array $extra = []): array {
		return $extra + [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_CONNECTTIMEOUT => $this->connectTimeoutSeconds,
			CURLOPT_TIMEOUT        => $this->requestTimeoutSeconds,
		];
	}

	/**
	 * @inheritDoc
	 */
	public function get(string $path, array $query = [], array $headers = []): ApiResponse {
		$separator = strpos($path, '?') !== false ? '&' : '?';
		$url       = $this->endpoint($path) . ($query === [] ? '' : $separator . http_build_query($query));

		return $this->send($url, $this->requestOptions($headers));
	}

	/**
	 * @inheritDoc
	 */
	public function post(string $path, array $data = [], array $headers = []): ApiResponse {
		return $this->send($this->endpoint($path), $this->requestOptions($headers, [
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS    => (string)json_encode($data),
		]));
	}

	/**
	 * @inheritDoc
	 */
	public function postForm(string $path, array $fields): ApiResponse {
		return $this->send($this->endpoint($path), $this->requestOptions([], [
			CURLOPT_POSTFIELDS => $fields,
		]));
	}

	/**
	 * Setzt Basis-URL und Pfad zusammen, ohne auf eine Framework-Konstante angewiesen zu sein.
	 *
	 * @param string $path The endpoint path.
	 *
	 * @return string
	 */
	private function endpoint(string $path): string {
		return $this->baseUrl . '/' . ltrim($path, '/');
	}

	/**
	 * @param string $url     The full request URL.
	 * @param array  $options The cURL options to apply.
	 *
	 * @return ApiResponse
	 */
	private function send(string $url, array $options): ApiResponse {
		try {
			// Ein NUL-Byte in der Basis-URL laesst curl_init() werfen, nicht false zurueckgeben.
			$curl = curl_init($url);
		} catch (ValueError $e) {
			return ApiResponse::transportError($e->getMessage());
		}

		curl_setopt_array($curl, $options);

		return $this->execute($curl);
	}

	/**
	 * @param CurlHandle $curl The prepared handle.
	 *
	 * @return ApiResponse
	 */
	private function execute(CurlHandle $curl): ApiResponse {
		$body     = curl_exec($curl);
		$status   = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
		$curlCode = curl_errno($curl);

		if ($curlCode !== 0) {
			return ApiResponse::transportError(curl_error($curl));
		}

		$decoded = is_string($body) ? json_decode($body, true) : null;

		if (!is_array($decoded)) {
			return new ApiResponse($status, null, 'Malformed response body.');
		}

		return new ApiResponse($status, $decoded);
	}
}
