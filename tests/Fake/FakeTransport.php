<?php
declare(strict_types=1);

namespace Heyrecruit\Test\Fake;

use Heyrecruit\Http\ApiResponse;
use Heyrecruit\Http\Transport;

/**
 * Transport, der vorgegebene Antworten ausliefert und jeden Aufruf mitschreibt.
 */
final class FakeTransport implements Transport {

	/** @var list<array{method: string, path: string, payload: array, headers: array}> */
	public array $calls = [];

	/** @var list<ApiResponse> Antworten fuer get() und post(). */
	private array $queue = [];

	/** @var list<ApiResponse> Antworten fuer postForm(), also den Auth-Endpunkt. */
	private array $authQueue = [];

	private ApiResponse $fallback;

	/**
	 * @param ApiResponse|null $fallback Returned once the queue is empty.
	 */
	public function __construct(?ApiResponse $fallback = null) {
		$this->fallback = $fallback ?? new ApiResponse(200, ['status' => 'success', 'data' => []]);
	}

	/**
	 * Erzeugt eine gueltige Auth-Antwort.
	 *
	 * @param string $token        The token to hand out.
	 * @param int    $validSeconds How long the token stays valid.
	 *
	 * @return ApiResponse
	 */
	public static function authSuccess(string $token = 'test-token', int $validSeconds = 3600): ApiResponse {
		return new ApiResponse(200, [
			'status' => 'success',
			'data'   => ['token' => $token, 'expiration' => time() + $validSeconds],
		]);
	}

	/**
	 * Erzeugt die Antwort, mit der die API einen abgelaufenen Token meldet.
	 *
	 * @return ApiResponse
	 */
	public static function expiredToken(): ApiResponse {
		return new ApiResponse(401, ['errors' => 'Expired token']);
	}

	/**
	 * @param ApiResponse ...$responses Responses handed out in order by get()/post().
	 *
	 * @return self
	 */
	public function queue(ApiResponse ...$responses): self {
		foreach ($responses as $response) {
			$this->queue[] = $response;
		}

		return $this;
	}

	/**
	 * @param ApiResponse ...$responses Responses handed out in order by the auth endpoint.
	 *
	 * @return self
	 */
	public function queueAuth(ApiResponse ...$responses): self {
		foreach ($responses as $response) {
			$this->authQueue[] = $response;
		}

		return $this;
	}

	/**
	 * @inheritDoc
	 */
	public function get(string $path, array $query = [], array $headers = []): ApiResponse {
		return $this->record('GET', $path, $query, $headers, $this->queue);
	}

	/**
	 * @inheritDoc
	 */
	public function post(string $path, array $data = [], array $headers = []): ApiResponse {
		return $this->record('POST', $path, $data, $headers, $this->queue);
	}

	/**
	 * @inheritDoc
	 */
	public function postForm(string $path, array $fields): ApiResponse {
		return $this->record('POST_FORM', $path, $fields, [], $this->authQueue, self::authSuccess());
	}

	/**
	 * Alle Aufrufe eines Pfades.
	 *
	 * @param string $path The endpoint path.
	 *
	 * @return list<array{method: string, path: string, payload: array, headers: array}>
	 */
	public function callsTo(string $path): array {
		return array_values(array_filter($this->calls, static fn (array $call): bool => $call['path'] === $path));
	}

	/**
	 * @param string           $method   The HTTP method or POST_FORM.
	 * @param string           $path     The endpoint path.
	 * @param array            $payload  Query parameters or body.
	 * @param array            $headers  The header lines.
	 * @param list<ApiResponse> $queue   The queue to draw from, by reference.
	 * @param ApiResponse|null $fallback Overrides the instance fallback.
	 *
	 * @return ApiResponse
	 */
	private function record(string $method, string $path, array $payload, array $headers, array &$queue, ?ApiResponse $fallback = null): ApiResponse {
		$this->calls[] = ['method' => $method, 'path' => $path, 'payload' => $payload, 'headers' => $headers];

		return array_shift($queue) ?? $fallback ?? $this->fallback;
	}
}
